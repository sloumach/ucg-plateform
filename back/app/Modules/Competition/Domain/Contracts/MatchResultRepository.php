<?php

namespace App\Modules\Competition\Domain\Contracts;

use App\Modules\Competition\Domain\Models\MatchResultEvent;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchResultRepository
{
    public function latest(string $org, string $match): ?MatchResultEvent;

    public function submission(MatchResultEvent $event): MatchResultEvent;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): MatchResultEvent;

    /** @return LengthAwarePaginator<int,MatchResultEvent> */
    public function history(string $org, string $match, int $page, int $perPage): LengthAwarePaginator;
}
