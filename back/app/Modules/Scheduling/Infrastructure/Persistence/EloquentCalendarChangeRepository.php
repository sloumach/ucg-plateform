<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarChangeRepository;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentCalendarChangeRepository implements CalendarChangeRepository
{
    public function __construct(private EloquentCalendarVisibility $visibility) {}

    public function append(array $attributes, array $people, array $accounts): CalendarChange
    {
        $change = new CalendarChange;
        $change->forceFill($attributes)->save();
        if ($people !== []) {
            CalendarChangeRecipient::query()->insert(array_map(fn (string $person): array => ['id' => (string) Str::uuid(),
                'organization_id' => $change->organization_id, 'change_id' => $change->id, 'person_id' => $person,
                'recipient_id' => $accounts[$person] ?? null, 'created_at' => $change->created_at], $people));
        }

        return $change;
    }

    public function history(string $org, string $activityId, array $filters): LengthAwarePaginator
    {
        return CalendarChange::query()->where('organization_id', $org)->where('activity_id', $activityId)
            ->withCount(['recipients', 'recipients as delivered_count' => fn (Builder $q) => $q->whereNotNull('delivered_at'),
                'recipients as blocked_count' => fn (Builder $q) => $q->whereNull('delivered_at')->whereNotNull('blocked_reason')])
            ->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function inbox(string $org, CalendarAudience $audience, array $filters): LengthAwarePaginator
    {
        return CalendarChangeRecipient::query()->where('organization_id', $org)->where('recipient_id', $audience->userId)
            ->where('person_id', $audience->personId)->whereNotNull('delivered_at')
            ->whereHas('change', fn (Builder $q) => $q->where('organization_id', $org)
                ->whereIn('activity_id', $this->visibility->query($org, $audience, includeCancelled: true)->select('id')))
            ->with(['change.activity:id,version'])->orderByDesc('created_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function sweep(string $org, int $limit): array
    {
        return array_values(CalendarChangeRecipient::query()->where('organization_id', $org)->whereNull('delivered_at')
            ->with('change')->orderByRaw('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END')->orderBy('checked_at')->orderBy('id')->limit($limit)->get()->all());
    }

    public function find(string $org, string $id, bool $lock = false): CalendarChangeRecipient
    {
        return CalendarChangeRecipient::query()->where('organization_id', $org)->with('change')
            ->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function update(CalendarChangeRecipient $notice, array $attributes): void
    {
        $notice->forceFill($attributes)->save();
    }
}
