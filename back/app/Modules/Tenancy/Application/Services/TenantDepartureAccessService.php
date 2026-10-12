<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDepartureAccess;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\InvitationRepository;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\InvitationStatus;
use App\Modules\Tenancy\Domain\MembershipStatus;
use Closure;
use Illuminate\Database\ConnectionInterface;

final readonly class TenantDepartureAccessService implements TenantDepartureAccess
{
    public function __construct(private ConnectionInterface $database, private AccountDirectory $accounts,
        private OrganizationRepository $organizations, private MembershipRepository $memberships,
        private InvitationRepository $invitations, private AccessAuditRepository $audit,
        private TenantAuthorization $authorization, private OrganizationAccessService $access, private RoleManagementService $roles) {}

    public function run(TenantContext $context, ?int $accountId, Closure $closePerson): mixed
    {
        return $this->database->transaction(function () use ($context, $accountId, $closePerson): mixed {
            // Preserve account -> organization -> person ordering, including unverified/revoked subjects.
            $email = $accountId === null ? null : $this->accounts->lockAccountEmail($accountId);
            $organization = $this->organizations->lock($context->organizationId);
            $this->authorization->authorize($context, 'organization.view');
            $this->authorization->authorize($context, 'memberships.manage');
            $this->authorization->authorize($context, 'invitations.manage');
            if ($accountId !== null && ($email === null || $accountId === $organization->owner_user_id)) {
                throw new DomainConflictException(__('people_lifecycle.owner'), 'PERSON_DEPARTURE_OWNER_PROTECTED');
            }
            $membership = $accountId === null ? null : $this->memberships->forAccount($organization->id, $accountId, true);
            if ($membership !== null && $membership->user_id !== $context->actorUserId) {
                $this->access->requireRoleDelegation($organization, $context->actorUserId, $membership->roles);
            }
            if ($email !== null && $accountId !== $context->actorUserId) {
                foreach ($this->invitations->pendingForEmail($organization->id, $email) as $invitation) {
                    $this->access->requireRoleDelegation($organization, $context->actorUserId, $invitation->roles, 'invitations.manage');
                }
            }
            $result = $closePerson();
            if ($email !== null) {
                foreach ($this->invitations->pendingForEmail($organization->id, $email) as $invitation) {
                    $before = $invitation->toArray();
                    $saved = $this->invitations->save($invitation, ['status' => InvitationStatus::Revoked]);
                    $this->audit->record(['organization_id' => $organization->id, 'actor_user_id' => $context->actorUserId,
                        'action' => 'invitation.revoked', 'subject_id' => $saved->id, 'before' => $before, 'after' => $saved->toArray(),
                        'request_id' => $context->requestId, 'occurred_at' => now()]);
                }
            }
            if ($membership !== null) {
                if ($membership->status !== MembershipStatus::Revoked) {
                    $before = $membership->toArray();
                    $saved = $this->memberships->save($membership, ['status' => MembershipStatus::Revoked]);
                    $this->audit->record(['organization_id' => $organization->id, 'actor_user_id' => $context->actorUserId,
                        'action' => 'membership.updated', 'subject_id' => $saved->id, 'before' => $before, 'after' => $saved->toArray(),
                        'request_id' => $context->requestId, 'occurred_at' => now()]);
                }
                $this->roles->revokeMembership($context, $membership->id);
            }

            return $result;
        }, 3);
    }
}
