<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\PartnershipMemberDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class PartnershipMemberService implements PartnershipMemberDirectory
{
    public function __construct(private MembershipRepository $members, private AccountDirectory $accounts,
        private TenantNotificationRecipientDirectory $recipients, private TenantAuthorization $authorization, private OrganizationRepository $organizations) {}

    public function paginate(TenantContext $context, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'partnerships.read');
        if ($page < 1 || $page > 100000 || $perPage < 1 || $perPage > 100) {
            throw new \InvalidArgumentException('Member references must be paginated.');
        }
        $result = $this->members->activePage($context->organizationId, $perPage, $page);
        $names = $this->accounts->displayNames(array_map(fn (OrganizationMembership $member): int => $member->user_id, array_values($result->items())));
        $result->setCollection($result->getCollection()->filter(fn (OrganizationMembership $member): bool => isset($names[$member->user_id]))->values());

        return $result->through(fn (OrganizationMembership $member): array => ['id' => (string) $member->user_id, 'label' => $names[$member->user_id]]);
    }

    public function labels(TenantContext $context, array $ids): array
    {
        $this->authorization->authorize($context, 'partnerships.read');
        if (count($ids) > 100) {
            throw new \InvalidArgumentException('Member labels require a bounded page.');
        }

        $effectiveIds = $this->members->activeAccountIds($context->organizationId, $ids);
        $owner = $this->organizations->findAccessible($context->organizationId, $context->actorUserId)->owner_user_id;
        if (in_array($owner, $ids, true)) {
            $effectiveIds[] = $owner;
        }

        return $this->accounts->displayNames(array_values(array_unique($effectiveIds)));
    }

    public function isEligible(TenantContext $context, int $accountId): bool
    {
        $this->authorization->authorize($context, 'partnerships.read');

        return $this->recipients->email($context->organizationId, $accountId, $context->requestId, ['partnerships.read']) !== null;
    }
}
