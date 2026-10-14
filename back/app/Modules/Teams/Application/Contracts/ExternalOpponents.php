<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;

interface ExternalOpponents
{
    /** Public sporting projection, captured atomically; the calling module authorizes its own object.
     * @return array<string, mixed>
     */
    public function capture(TenantContext $context, string $opponentId, string $version, string $contextLabel, bool $confirmPublicData): array;

    /** Historical projection only, with current tenant authorization rechecked.
     * @return array<string, mixed>
     */
    public function snapshot(TenantContext $context, string $snapshotId): array;
}
