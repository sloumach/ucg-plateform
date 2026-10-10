<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\MembershipData;
use App\Modules\Tenancy\Application\Validation\MembershipInput;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Exceptions\InvalidOrganizationAccountException;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class MembershipService
{
    public function __construct(
        private MembershipRepository $memberships,
        private OrganizationRepository $organizations,
        private OrganizationAccessService $access,
        private MembershipAdmissionService $admission,
        private AccountDirectory $accounts,
        private AccessAuditRepository $audit,
        private ConnectionInterface $database,
        private Factory $validator,
    ) {}

    /** @return LengthAwarePaginator<int, OrganizationMembership> */
    public function list(TenantContext $context, int $perPage, int $page): LengthAwarePaginator
    {
        $this->access->requireAdministration($this->access->find($context->organizationId, $context->actorUserId), $context->actorUserId);

        return $this->memberships->paginate($context->organizationId, $perPage, $page);
    }

    public function findForUser(string $id, int $userId): OrganizationMembership
    {
        return $this->memberships->findForUser($id, $userId);
    }

    public function update(TenantContext $context, string $membershipId, MembershipData $data): OrganizationMembership
    {
        $attributes = ['status' => $data->status->value, 'roles' => $data->roles, 'starts_at' => $data->startsAt, 'ends_at' => $data->endsAt];
        $this->validator->make($attributes, MembershipInput::periodRules(), MembershipInput::messages())->validate();
        $attributes['starts_at'] = CarbonImmutable::parse($attributes['starts_at'])->utc();
        $attributes['ends_at'] = $attributes['ends_at'] === null ? null : CarbonImmutable::parse($attributes['ends_at'])->utc();
        $initial = $this->memberships->find($context->organizationId, $membershipId);

        return $this->database->transaction(function () use ($context, $initial, $data, $attributes): OrganizationMembership {
            if ($this->accounts->lockVerifiedEmail($initial->user_id) === null) {
                throw new InvalidOrganizationAccountException;
            }
            $organization = $this->organizations->lock($context->organizationId);
            if (! $organization->acceptsBusinessOperations()) {
                throw new DomainConflictException(__('tenancy.errors.inactive'), 'ORGANIZATION_INACTIVE');
            }
            $membership = $this->memberships->forAccount($organization->id, $initial->user_id, true);
            if ($membership === null || $membership->id !== $initial->id) {
                throw new ModelNotFoundException;
            }
            $this->access->requireRoleDelegation($organization, $context->actorUserId, array_values(array_unique([...$membership->roles, ...$data->roles])));
            if ($membership->user_id === $organization->owner_user_id) {
                throw new DomainConflictException(__('tenancy.errors.owner_membership'), 'OWNER_MEMBERSHIP_PROTECTED');
            }
            if ($data->status !== MembershipStatus::Revoked) {
                $this->admission->ensureAllowed($organization->id, $membership->user_id, $attributes['starts_at'], $attributes['ends_at']);
            }
            $before = $membership->toArray();
            $result = $this->memberships->save($membership, $attributes);
            $this->record($context, 'membership.updated', $result, $before);

            return $result;
        });
    }

    /** Explicit self-departure remains possible even when the tenant is inactive. */
    public function leave(string $membershipId, int $userId, string $requestId): OrganizationMembership
    {
        $initial = $this->memberships->findForUser($membershipId, $userId);

        return $this->database->transaction(function () use ($initial, $userId, $requestId): OrganizationMembership {
            if ($this->accounts->lockVerifiedEmail($userId) === null) {
                throw new InvalidOrganizationAccountException;
            }
            $organization = $this->organizations->lock($initial->organization_id);
            if ($organization->owner_user_id === $userId) {
                throw new DomainConflictException(__('tenancy.errors.owner_membership'), 'OWNER_MEMBERSHIP_PROTECTED');
            }
            $membership = $this->memberships->forAccount($organization->id, $userId, true);
            if ($membership === null || $membership->id !== $initial->id) {
                throw new ModelNotFoundException;
            }
            $before = $membership->toArray();
            $result = $this->memberships->save($membership, ['status' => MembershipStatus::Revoked]);
            $this->audit->record(['organization_id' => $organization->id, 'actor_user_id' => $userId,
                'action' => 'membership.left', 'subject_id' => $result->id, 'before' => $before,
                'after' => $result->toArray(), 'request_id' => $requestId, 'occurred_at' => now()]);

            return $result;
        });
    }

    /** @param array<string, mixed>|null $before */
    private function record(TenantContext $context, string $action, OrganizationMembership $membership, ?array $before): void
    {
        $this->audit->record(['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId,
            'action' => $action, 'subject_id' => $membership->id, 'before' => $before,
            'after' => $membership->toArray(), 'request_id' => $context->requestId, 'occurred_at' => now()]);
    }
}
