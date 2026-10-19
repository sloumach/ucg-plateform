<?php

namespace App\Modules\Competition\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;

interface ObservationMatchDirectory
{
    /** Ordinary audience only; composable subquery avoids an unbounded identifier list. */
    public function visibleIds(TenantContext $context): Builder;

    /** @return array{match_id:string,kind:string} */
    public function assertReference(TenantContext $context, string $match, string $game, ?string $part): array;

    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, string $game, int $page): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function parts(TenantContext $context, string $match, int $page): LengthAwarePaginator;
}
