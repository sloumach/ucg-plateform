<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\MatchNotebookRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchNotebookRepository
{
    /** @param list<string> $audiences
     * @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,MatchNotebookRevision> */
    public function paginate(string $org, string $match, array $audiences, array $filters): LengthAwarePaginator;

    /** @param list<string> $audiences */
    public function latest(string $org, string $match, string $entry, array $audiences): MatchNotebookRevision;

    /** @return LengthAwarePaginator<int,MatchNotebookRevision> */
    public function history(string $org, string $match, string $entry, int $page, int $perPage): LengthAwarePaginator;

    public function count(string $org, string $match): int;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): MatchNotebookRevision;
}
