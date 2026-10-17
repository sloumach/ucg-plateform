<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\CalendarConflictRepository;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarConflictOverride;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentCalendarConflictRepository implements CalendarConflictRepository
{
    public function detect(string $org, ?string $except, CarbonImmutable $from, CarbonImmutable $to, ?string $team, array $people, array $resources, bool $availability, int $limit): array
    {
        $rows = [];
        if ($team !== null || $people !== [] || $resources !== []) {
            $query = CalendarActivity::query()->where('organization_id', $org)->whereNull('archived_at')->where('starts_at', '<', $to)->where('ends_at', '>', $from)
                ->when($except !== null, fn (Builder $q) => $q->whereKeyNot($except))
                ->where(function (Builder $q) use ($team, $people, $resources): void {
                    $q->whereRaw('1=0');
                    if ($team !== null) {
                        $q->orWhere('team_id', $team);
                    }
                    if ($people !== []) {
                        $q->orWhereHas('participants', fn (Builder $p) => $p->whereIn('person_id', $people));
                    }
                    if ($resources !== []) {
                        $q->orWhereHas('reservations', fn (Builder $r) => $r->whereIn('resource_id', $resources));
                    }
                });
            foreach ($query->with(['participants', 'reservations'])->orderBy('id')->limit($limit)->get() as $item) {
                $personIds = array_values(array_intersect($people, $item->participants->pluck('person_id')->all()));
                $resourceIds = array_values(array_intersect($resources, $item->reservations->pluck('resource_id')->all()));
                $causes = [];
                if ($personIds !== []) {
                    $causes[] = 'person';
                }
                if ($team !== null && $item->team_id === $team) {
                    $causes[] = 'team';
                }
                if ($resourceIds !== []) {
                    $causes[] = 'resource';
                }
                $rows[] = ['type' => 'activity', 'id' => $item->id, 'version' => $item->version, 'title' => $item->title, 'starts_at' => $item->starts_at->utc()->toIso8601String(),
                    'ends_at' => $item->ends_at->utc()->toIso8601String(), 'causes' => $causes, 'person_ids' => $personIds, 'resource_ids' => $resourceIds];
            }
        }
        if ($availability && $people !== []) {
            foreach (Availability::query()->where('organization_id', $org)->whereIn('person_id', $people)->whereIn('status', ['unavailable', 'uncertain'])
                ->whereNull('cancelled_at')->where('starts_at', '<', $to)->where('ends_at', '>', $from)->orderBy('id')->limit($limit)->get(['id', 'version', 'person_id', 'status', 'starts_at', 'ends_at']) as $item) {
                $rows[] = ['type' => $item->status, 'id' => $item->id, 'version' => $item->version, 'title' => null, 'starts_at' => $item->starts_at->utc()->toIso8601String(),
                    'ends_at' => $item->ends_at->utc()->toIso8601String(), 'causes' => ['availability'], 'person_ids' => [$item->person_id], 'resource_ids' => []];
            }
        }
        usort($rows, fn (array $a, array $b): int => strcmp($a['type'].':'.$a['id'], $b['type'].':'.$b['id']));

        return array_slice($rows, 0, $limit);
    }

    public function recordOverride(CalendarActivity $activity, int $actor, string $reason, array $snapshot, int $count): void
    {
        (new CalendarConflictOverride)->forceFill(['organization_id' => $activity->organization_id, 'activity_id' => $activity->id, 'activity_version' => $activity->version,
            'actor_user_id' => $actor, 'reason' => $reason, 'snapshot' => $snapshot, 'conflict_count' => $count, 'created_at' => now()])->save();
    }

    public function history(string $org, string $activity, int $page, int $perPage): LengthAwarePaginator
    {
        return CalendarConflictOverride::query()->where('organization_id', $org)->where('activity_id', $activity)->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }
}
