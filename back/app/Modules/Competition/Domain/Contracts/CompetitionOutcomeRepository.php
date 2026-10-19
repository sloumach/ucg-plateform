<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\CompetitionOutcomeRevision as Revision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompetitionOutcomeRepository
{
    public function latest(string $org, string $competition, string $stream): ?Revision;

    /** @param array<string,mixed> $attributes
     * @param list<array{reference_id:string,match_id:string}> $links */
    public function append(array $attributes, array $links): Revision;

    /** @return LengthAwarePaginator<int,Revision> */
    public function history(string $org, string $competition, string $stream, int $page, int $size): LengthAwarePaginator;
}
