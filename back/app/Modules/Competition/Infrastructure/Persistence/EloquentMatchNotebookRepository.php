<?php

namespace App\Modules\Competition\Infrastructure\Persistence;

use App\Modules\Competition\Domain\Contracts\MatchNotebookRepository;
use App\Modules\Competition\Domain\Models\MatchNotebookRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;

final class EloquentMatchNotebookRepository implements MatchNotebookRepository
{
    /** @return Builder<MatchNotebookRevision> */
    private function query(string $org, string $match): Builder
    {
        return MatchNotebookRevision::query()->where('organization_id', $org)->where('match_id', $match);
    }

    public function paginate(string $org, string $match, array $audiences, array $filters): LengthAwarePaginator
    {
        $q = $this->query($org, $match)->whereIn('audience', $audiences)->whereNotExists(fn (Query $newer) => $newer->selectRaw('1')->from('match_notebook_revisions as newer')
            ->where('newer.organization_id', $org)->where('newer.match_id', $match)->whereColumn('newer.entry_id', 'match_notebook_revisions.entry_id')->whereColumn('newer.number', '>', 'match_notebook_revisions.number'));
        foreach (['kind', 'audience', 'state'] as $field) {
            if (isset($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }

        return $q->orderByDesc('created_at')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function latest(string $org, string $match, string $entry, array $audiences): MatchNotebookRevision
    {
        return $this->query($org, $match)->where('entry_id', $entry)->whereIn('audience', $audiences)->orderByDesc('number')->firstOrFail();
    }

    public function history(string $org, string $match, string $entry, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->query($org, $match)->where('entry_id', $entry)->orderByDesc('number')->paginate($perPage, ['*'], 'page', $page);
    }

    public function count(string $org, string $match): int
    {
        return $this->query($org, $match)->where('number', 1)->count();
    }

    public function append(array $attributes): MatchNotebookRevision
    {
        $revision = new MatchNotebookRevision;
        $revision->forceFill($attributes)->save();

        return $revision;
    }
}
