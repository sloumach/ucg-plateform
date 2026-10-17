<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\MatchRepository;
use App\Modules\Competition\Domain\Models\MatchParticipant;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Domain\Models\MatchStatusEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Support\Str;

final readonly class EloquentMatchRepository implements MatchRepository
{
    public function paginate(string $org, int $actor, array $viewer, array $filters, bool $management): LengthAwarePaginator
    {
        $query = $this->visible($org, $actor, $viewer, $management)->with('participants');
        foreach (['game_id', 'status', 'kind'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderByDesc('starts_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $id, int $actor, array $viewer, bool $management, bool $lock = false): MatchRecord
    {
        return $this->visible($org, $actor, $viewer, $management)->with('participants')->when($lock, fn (Builder $q) => $q->lockForUpdate())->findOrFail($id);
    }

    public function save(?MatchRecord $record, array $attributes, ?array $participants): MatchRecord
    {
        $record ??= new MatchRecord;
        $record->forceFill($attributes)->save();
        if ($participants !== null) {
            MatchParticipant::query()->where('organization_id', $record->organization_id)->where('match_id', $record->id)->delete();
            $rows = [];
            foreach ($participants as $slot => $attributes) {
                $participant = new MatchParticipant;
                $participant->forceFill(['team_id' => null, 'person_id' => null, 'opponent_snapshot_id' => null, ...$attributes,
                    'id' => (string) Str::uuid(), 'organization_id' => $record->organization_id, 'match_id' => $record->id, 'slot' => $slot + 1]);
                $rows[] = $participant->getAttributes();
            }
            MatchParticipant::query()->insert($rows);
        }

        return $record->load('participants');
    }

    public function event(array $attributes): void
    {
        $event = new MatchStatusEvent;
        $event->forceFill($attributes)->save();
    }

    public function history(string $org, string $id, int $page, int $perPage): LengthAwarePaginator
    {
        return MatchStatusEvent::query()->where('organization_id', $org)->where('match_id', $id)->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    /** @param array{person_id:string|null,team_ids:list<string>} $viewer
     * @return Builder<MatchRecord>
     */
    private function visible(string $org, int $actor, array $viewer, bool $management): Builder
    {
        $query = MatchRecord::query()->where('organization_id', $org);
        if ($management) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q->where('created_by', $actor)->orWhere('responsible_person_id', $viewer['person_id'])
            ->orWhere('visibility', 'organization')
            ->orWhereExists(fn (Query $p) => $p->selectRaw('1')->from('match_participants as member')
                ->where('member.organization_id', $org)->whereColumn('member.match_id', 'matches.id')
                ->where(fn (Query $scope) => $scope->where('member.person_id', $viewer['person_id'])
                    ->orWhere(fn (Query $team) => $team->where('matches.visibility', 'team')->whereIn('member.team_id', $viewer['team_ids'])))));
    }
}
