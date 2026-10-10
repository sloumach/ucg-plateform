<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface InvitationRepository
{
    /** @return LengthAwarePaginator<int, OrganizationInvitation> */
    public function paginateForOrganization(string $organizationId, int $perPage, int $page): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int, OrganizationInvitation> */
    public function paginateForEmail(string $email, int $perPage, int $page): LengthAwarePaginator;

    public function find(string $id, bool $lock = false): OrganizationInvitation;

    /** @return list<OrganizationInvitation> */
    public function pendingForEmail(string $organizationId, string $email): array;

    /** @param array<string, mixed> $attributes */
    public function save(?OrganizationInvitation $invitation, array $attributes): OrganizationInvitation;
}
