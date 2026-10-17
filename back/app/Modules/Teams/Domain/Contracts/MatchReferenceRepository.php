<?php

namespace App\Modules\Teams\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchReferenceRepository
{
    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(string $org, string $type, ?string $gameId, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string,array{label:string,version:string}>
     */
    public function snapshots(string $org, string $type, array $ids, string $gameId, string $gameKey): array;
}
