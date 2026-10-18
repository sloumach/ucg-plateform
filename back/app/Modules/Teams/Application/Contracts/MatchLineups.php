<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MatchLineups
{
    /** @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, string $teamId, int $page, int $perPage): LengthAwarePaginator;

    /** @return array<string,mixed> */
    public function snapshot(TenantContext $context, string $teamId, string $lineupId, string $gameId): array;

    /** Rechecks eligibility at match time; never replaces frozen sporting labels.
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function assess(TenantContext $context, array $snapshot, CarbonImmutable $at): array;
}
