<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Str;

final readonly class EloquentCalendarRepository implements CalendarRepository
{
    public function paginate(string $org, CalendarAudience $audience, CarbonImmutable $from, CarbonImmutable $to, array $filters, bool $management): LengthAwarePaginator
    {
        $query = $this->visible($org, $audience, $management)->where('starts_at', '<', $to)->where('ends_at', '>', $from);
        $query->when($filters['archived'] ?? false, fn (Builder $q) => $q->whereNotNull('archived_at'), fn (Builder $q) => $q->whereNull('archived_at'));
        foreach (['kind', 'team_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->with('participants')->orderBy('starts_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, CalendarAudience $audience, bool $management, bool $lock = false): CalendarActivity
    {
        $query = $this->visible($org, $audience, $management)->with('participants');

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?CalendarActivity $record, array $attributes, ?array $personIds): CalendarActivity
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

        return $record->load('participants');
    }

    /** @return Builder<CalendarActivity> */
    private function visible(string $org, CalendarAudience $audience, bool $management): Builder
    {
        $query = CalendarActivity::query()->where('organization_id', $org);
        if ($management) {
            return $query;
        }

        return $query->whereNull('archived_at')->where(fn (Builder $q) => $q->where('created_by', $audience->userId)->orWhere('visibility', 'organization')
            ->orWhere(fn (Builder $team) => $team->where('visibility', 'team')->whereIn('team_id', $audience->teamIds))
            ->orWhereExists(fn (Query $participant) => $participant->selectRaw('1')->from('calendar_participants as participant')
                ->where('participant.organization_id', $org)->where('participant.person_id', $audience->personId)
                ->whereColumn('participant.activity_id', 'calendar_activities.id')));
    }
}
