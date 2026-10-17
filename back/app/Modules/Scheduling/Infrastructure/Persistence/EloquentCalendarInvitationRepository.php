<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\CalendarInvitationRepository;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarInvitationDelivery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

final readonly class EloquentCalendarInvitationRepository implements CalendarInvitationRepository
{
    public function paginate(string $org, array $filters, ?string $activityId, ?string $personId, int $actorId): LengthAwarePaginator
    {
        $query = CalendarInvitation::query()->where('organization_id', $org);
        if ($activityId !== null) {
            $query->where('activity_id', strtolower($activityId));
        } else {
            $query->where('recipient_id', $actorId)->where('person_id', $personId)->whereNotNull('sent_at');
            $this->current($query, $org);
        }

        return $query->with(['activity.participants'])->withCount('deliveries')->orderByDesc('created_at')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, bool $lock = false): CalendarInvitation
    {
        $query = CalendarInvitation::query()->where('organization_id', $org)->with(['activity.participants'])->withCount('deliveries');

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function exists(string $org, string $activityId, string $activityVersion): bool
    {
        return CalendarInvitation::query()->where('organization_id', $org)->where('activity_id', $activityId)->where('activity_version', $activityVersion)->exists();
    }

    public function create(array $attributes): CalendarInvitation
    {
        $record = new CalendarInvitation;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function update(CalendarInvitation $record, array $attributes): void
    {
        $record->forceFill($attributes)->save();
    }

    public function sweep(string $org, int $limit): array
    {
        $query = CalendarInvitation::query()->where('organization_id', $org)->where('response', 'pending')->where('response_deadline', '>', now());
        $this->current($query, $org);

        return array_values($query->orderByRaw('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END')->orderBy('checked_at')->orderBy('id')->limit($limit)->get()->all());
    }

    public function deliver(CalendarInvitation $record, string $kind, int $offset, string $runId): void
    {
        $attributes = ['organization_id' => $record->organization_id, 'invitation_id' => $record->id, 'kind' => $kind, 'offset_minutes' => $offset];
        if (! CalendarInvitationDelivery::query()->where($attributes)->exists()) {
            (new CalendarInvitationDelivery)->forceFill([...$attributes, 'run_id' => $runId])->save();
        }
    }

    public function history(CalendarInvitation $record, array $filters): LengthAwarePaginator
    {
        return CalendarInvitationDelivery::query()->where('organization_id', $record->organization_id)->where('invitation_id', $record->id)
            ->orderByDesc('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    /** @param Builder<CalendarInvitation> $query */
    private function current(Builder $query, string $org): void
    {
        $query->whereHas('activity', fn (Builder $activity) => $activity->where('organization_id', $org)->whereNull('archived_at')
            ->whereColumn('calendar_activities.version', 'calendar_invitations.activity_version'))
            ->whereExists(fn (Query $p) => $p->selectRaw('1')->from('calendar_participants as p')->where('p.organization_id', $org)
                ->whereColumn('p.activity_id', 'calendar_invitations.activity_id')->whereColumn('p.person_id', 'calendar_invitations.person_id'));
    }
}
