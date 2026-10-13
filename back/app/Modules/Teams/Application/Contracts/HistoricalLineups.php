<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;

interface HistoricalLineups
{
    /** Frozen sporting projection for future match/registration modules; no current People lookup.
     * @return array<string,mixed>
     */
    public function snapshot(TenantContext $context, string $teamId, string $lineupId): array;
}
