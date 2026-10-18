<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\MatchLineupRepository;
use App\Modules\Competition\Domain\Models\MatchLineupRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

final class EloquentMatchLineupRepository implements MatchLineupRepository
{
    /** @return Builder<MatchLineupRevision> */
    private function query(string $org, string $match): Builder
    {
        return MatchLineupRevision::query()->where('organization_id', $org)->where('match_id', $match);
    }

    public function count(string $org, string $match): int
    {
        return $this->query($org, $match)->count();
    }

    public function latest(string $org, string $match, string $participant): ?MatchLineupRevision
    {
        return $this->query($org, $match)->where('participant_id', $participant)->orderByDesc('number')->first();
    }

    public function append(array $attributes): MatchLineupRevision
    {
        $revision = new MatchLineupRevision;
        $revision->forceFill($attributes)->save();

        return $revision;
    }

    public function current(string $org, string $match): array
    {
        $rows = $this->query($org, $match)->whereNotExists(fn (Query $newer) => $newer->selectRaw('1')->from('match_lineup_revisions as newer')
            ->whereColumn('newer.organization_id', 'match_lineup_revisions.organization_id')
            ->whereColumn('newer.match_id', 'match_lineup_revisions.match_id')->whereColumn('newer.participant_id', 'match_lineup_revisions.participant_id')
            ->whereColumn('newer.number', '>', 'match_lineup_revisions.number'))->orderBy('participant_id')->limit(100)->get();
        $current = [];
        foreach ($rows as $row) {
            $current[$row->participant_id] = $row;
        }

        return $current;
    }

    public function history(string $org, string $match, string $participant, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($org, $match)->where('participant_id', $participant)->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }
}
