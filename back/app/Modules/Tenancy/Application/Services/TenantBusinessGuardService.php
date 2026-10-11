<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class TenantBusinessGuardService implements TenantBusinessGuard
{
    public function __construct(private OrganizationRepository $organizations, private MembershipRepository $memberships,
        private AccountDirectory $accounts, private TenantAuthorization $authorization, private ConnectionInterface $database) {}

    public function lock(TenantContext $context, ?int $accountId = null): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Tenant business guards require the business transaction.');
        }
        // Keep the established account -> organization lock order used by tenant admission.
        $verified = $accountId === null || $this->accounts->lockVerifiedEmail($accountId) !== null;
        $organization = $this->organizations->lock($context->organizationId);
        $this->authorization->authorize($context, 'organization.view');

        if ($accountId !== null && (! $verified || ($organization->owner_user_id !== $accountId
            && ! ($this->memberships->forAccount($organization->id, $accountId)?->isEffective(now()) ?? false)))) {
            throw new DomainConflictException(__('people.account_unavailable'), 'PERSON_ACCOUNT_UNAVAILABLE');
        }
    }
}
