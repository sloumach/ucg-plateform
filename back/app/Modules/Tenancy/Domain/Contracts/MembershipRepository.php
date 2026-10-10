<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface MembershipRepository
{
    /** @return LengthAwarePaginator<int, OrganizationMembership> */
    public function paginate(string $organizationId, int $perPage, int $page): LengthAwarePaginator;

    public function find(string $organizationId, string $id): OrganizationMembership;

    public function findForUser(string $id, int $userId): OrganizationMembership;

    public function forAccount(string $organizationId, int $userId, bool $lock = false): ?OrganizationMembership;

    public function overlapsAnotherOrganization(?string $organizationId, int $userId, CarbonInterface $start, ?CarbonInterface $end): bool;

    /** @param array<string, mixed> $attributes */
    public function save(?OrganizationMembership $membership, array $attributes): OrganizationMembership;
}
