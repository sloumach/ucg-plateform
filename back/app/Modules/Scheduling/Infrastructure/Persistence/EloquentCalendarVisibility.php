<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

/** One audience predicate for the calendar, ICS and change notifications. */
final readonly class EloquentCalendarVisibility
{
    /** @return Builder<CalendarActivity> */
    public function query(string $org, CalendarAudience $audience, bool $management = false, bool $includeCancelled = false): Builder
    {
        $query = CalendarActivity::query()->where('organization_id', $org);
        if ($management) {
            return $query;
        }
        $query->where(fn (Builder $q) => $q->whereNull('archived_at')->when($includeCancelled, fn (Builder $c) => $c->orWhereNotNull('cancelled_at')));

        return $query->where(fn (Builder $q) => $q->where('created_by', $audience->userId)->orWhere('visibility', 'organization')
            ->orWhere(fn (Builder $team) => $team->where('visibility', 'team')->whereIn('team_id', $audience->teamIds))
            ->orWhereExists(fn (Query $participant) => $participant->selectRaw('1')->from('calendar_participants as participant')
                ->where('participant.organization_id', $org)->where('participant.person_id', $audience->personId)
                ->whereColumn('participant.activity_id', 'calendar_activities.id')));
    }
}
