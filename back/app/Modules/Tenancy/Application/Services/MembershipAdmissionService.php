<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;

final readonly class MembershipAdmissionService
{
    public function __construct(private MembershipRepository $memberships, private OrganizationRepository $organizations, private Repository $config) {}

    /** The caller must hold the verified account row lock for the whole admission transaction. */
    public function ensureAllowed(?string $organizationId, int $userId, CarbonInterface $start, ?CarbonInterface $end): void
    {
        if ($this->config->get('tenancy.allow_multiple_organizations', true) === true) {
            return;
        }
        if ($this->memberships->overlapsAnotherOrganization($organizationId, $userId, $start, $end)
            || $this->organizations->ownsAnotherUnarchivedOrganization($organizationId, $userId)) {
            throw new DomainConflictException(__('tenancy.errors.exclusive_membership'), 'MULTIPLE_ORGANIZATIONS_FORBIDDEN');
        }
    }
}
