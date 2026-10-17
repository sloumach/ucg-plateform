<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchReferenceDirectory
{
    /** @return array{person_id:string|null,team_ids:list<string>} */
    public function viewer(TenantContext $context): array;

    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, string $type, ?string $gameId, int $page, int $perPage): LengthAwarePaginator;

    /** @return array{id:string,key:string,name:string} */
    public function game(TenantContext $context, string $id): array;

    /** @return array{label:string,version:string} */
    public function participant(TenantContext $context, string $type, string $id, string $gameId): array;

    /** @param list<array{kind:string,source_id:string}> $participants
     * @return list<array{kind:string,source_id:string,label:string,version:string}>
     */
    public function participants(TenantContext $context, array $participants, string $gameId): array;
}
