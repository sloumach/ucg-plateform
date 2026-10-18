<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\MatchLineupRevision;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchLineupRepository
{
    public function count(string $org, string $match): int;

    public function latest(string $org, string $match, string $participant): ?MatchLineupRevision;

    /** @return array<string,MatchLineupRevision> */
    public function current(string $org, string $match): array;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): MatchLineupRevision;

    /** @return LengthAwarePaginator<int,MatchLineupRevision> */
    public function history(string $org, string $match, string $participant, int $page, int $perPage): LengthAwarePaginator;
}
