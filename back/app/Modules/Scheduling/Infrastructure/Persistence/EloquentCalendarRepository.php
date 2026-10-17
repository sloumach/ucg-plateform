<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\CalendarIcs;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentCalendarRepository implements CalendarRepository
{
    public function __construct(private EloquentCalendarVisibility $visibility) {}

    public function visibleForChange(string $org, string $id, CalendarAudience $audience): bool
    {
        return $this->visibility->query($org, $audience, includeCancelled: true)->whereKey($id)->exists();
    }

    public function feed(string $org, CalendarAudience $audience, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return array_values($this->visible($org, $audience, false)->where('starts_at', '<', $to)->where('ends_at', '>', $from)
            ->orderBy('starts_at')->orderBy('id')->limit(CalendarIcs::MAX_EVENTS + 1)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'updated_at'])
            ->map(fn (CalendarActivity $event): array => ['id' => $event->id, 'title' => $event->title,
                'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'updated_at' => CarbonImmutable::instance($event->updated_at ?? $event->starts_at)])->all());
    }

    public function paginate(string $org, CalendarAudience $audience, CarbonImmutable $from, CarbonImmutable $to, array $filters, bool $management): LengthAwarePaginator
    {
        $query = $this->visible($org, $audience, $management)->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        $query->when($filters['archived'] ?? false, fn (Builder $q) => $q->whereNotNull('archived_at'), fn (Builder $q) => $q->whereNull('archived_at'));
        foreach (['kind', 'team_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->with(['participants', 'reservations.resource'])->orderBy('starts_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, CalendarAudience $audience, bool $management, bool $lock = false): CalendarActivity
    {
        $query = $this->visible($org, $audience, $management)->with(['participants', 'reservations.resource']);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?CalendarActivity $record, array $attributes, ?array $personIds, ?array $resourceIds = null): CalendarActivity
    {
        $record ??= new CalendarActivity;
        $record->forceFill($attributes)->save();
        if ($personIds !== null) {
            $record->participants()->delete();
            if ($personIds !== []) {
                $record->participants()->insert(array_map(fn (string $personId): array => ['id' => (string) Str::uuid(), 'organization_id' => $record->organization_id,
                    'activity_id' => $record->id, 'person_id' => $personId, 'created_at' => now()], $personIds));
            }
        }

        if ($resourceIds !== null) {
            $record->reservations()->delete();
            if ($resourceIds !== []) {
                $record->reservations()->insert(array_map(fn (string $resourceId): array => ['id' => (string) Str::uuid(),
                    'organization_id' => $record->organization_id, 'activity_id' => $record->id, 'resource_id' => $resourceId, 'created_at' => now()], $resourceIds));
            }
        }

        return $record->load(['participants', 'reservations.resource']);
    }

    /** @return Builder<CalendarActivity> */
    private function visible(string $org, CalendarAudience $audience, bool $management): Builder
    {
        return $this->visibility->query($org, $audience, $management);
    }
}
