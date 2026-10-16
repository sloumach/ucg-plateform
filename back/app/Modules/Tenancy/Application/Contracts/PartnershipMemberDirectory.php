<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface PartnershipMemberDirectory
{
    /** Active verified member display names only; no emails, roles or HR attributes.
     * @return LengthAwarePaginator<int, array{id: string, label: string}>
     */
    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<int> $ids
     * @return array<int, string> Current member display names only. */
    public function labels(TenantContext $context, array $ids): array;

    /** Current verified member with MFA and partnership read rights; no email or account details returned. */
    public function isEligible(TenantContext $context, int $accountId): bool;
}
