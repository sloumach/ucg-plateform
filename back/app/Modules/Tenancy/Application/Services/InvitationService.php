<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\InvitationData;
use App\Modules\Tenancy\Application\Validation\MembershipInput;
use App\Modules\Tenancy\Domain\Contracts\AccessAuditRepository;
use App\Modules\Tenancy\Domain\Contracts\InvitationNotifier;
use App\Modules\Tenancy\Domain\Contracts\InvitationRepository;
use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Exceptions\InvalidOrganizationAccountException;
use App\Modules\Tenancy\Domain\InvitationStatus;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final readonly class InvitationService
{
    public function __construct(
        private InvitationRepository $invitations,
        private MembershipRepository $memberships,
        private OrganizationRepository $organizations,
        private AccountDirectory $accounts,
        private OrganizationAccessService $access,
        private MembershipAdmissionService $admission,
        private AccessAuditRepository $audit,
        private InvitationNotifier $notifier,
        private ConnectionInterface $database,
        private Factory $validator,
        private Repository $config,
    ) {}

    /** @return LengthAwarePaginator<int, OrganizationInvitation> */
    public function inbox(int $userId, int $perPage, int $page): LengthAwarePaginator
    {
        $email = $this->accounts->verifiedEmail($userId);
        if ($email === null) {
            throw new InvalidOrganizationAccountException;
        }

        return $this->invitations->paginateForEmail($email, $perPage, $page);
    }

    public function findForRecipient(string $id, int $userId): OrganizationInvitation
    {
        $invitation = $this->invitations->find($id);
        if ($this->accounts->verifiedEmail($userId) !== $invitation->email) {
            throw new ModelNotFoundException;
        }

        return $invitation;
    }

    /** @return LengthAwarePaginator<int, OrganizationInvitation> */
    public function list(TenantContext $context, int $perPage, int $page): LengthAwarePaginator
    {
        $this->access->requireAdministration($this->access->find($context->organizationId, $context->actorUserId), $context->actorUserId);

        return $this->invitations->paginateForOrganization($context->organizationId, $perPage, $page);
    }

    public function invite(TenantContext $context, InvitationData $data): OrganizationInvitation
    {
        $start = $data->startsAt ?? now()->toIso8601String();
        $attributes = ['email' => Str::lower(trim($data->email)), 'roles' => $data->roles, 'starts_at' => $start, 'ends_at' => $data->endsAt];
        $this->validator->make($attributes, [...MembershipInput::periodRules(), 'email' => ['required', 'string', 'email:rfc', 'max:254']], MembershipInput::messages())->validate();
        // Eloquent's database date format omits offsets: normalize before persistence/query binding.
        $attributes['starts_at'] = CarbonImmutable::parse($attributes['starts_at'])->utc();
        $attributes['ends_at'] = $attributes['ends_at'] === null ? null : CarbonImmutable::parse($attributes['ends_at'])->utc();

        return $this->database->transaction(function () use ($context, $attributes): OrganizationInvitation {
            $organization = $this->organizations->lock($context->organizationId);
            $this->requireActive($organization->acceptsBusinessOperations());
            $this->access->requireRoleDelegation($organization, $context->actorUserId, $attributes['roles']);
            foreach ($this->invitations->pendingForEmail($organization->id, $attributes['email']) as $previous) {
                if ($previous->effectiveStatus(now()) !== InvitationStatus::Expired) {
                    throw new DomainConflictException(__('tenancy.errors.invitation_pending'), 'INVITATION_ALREADY_PENDING');
                }
                $before = $previous->attributesToArray();
                $this->invitations->save($previous, ['status' => InvitationStatus::Expired]);
                $this->record($previous, $context->actorUserId, $context->requestId, 'invitation.expired', $before);
            }
            $invitation = $this->invitations->save(null, [...$attributes, 'organization_id' => $organization->id,
                'invited_by' => $context->actorUserId, 'status' => InvitationStatus::Pending,
                'expires_at' => now()->addDays((int) $this->config->get('tenancy.invitation_lifetime_days', 7))]);
            $this->record($invitation, $context->actorUserId, $context->requestId, 'invitation.created', null);
            $this->notifier->notify($invitation, $organization->name, $context->requestId);

            return $invitation;
        });
    }

    public function respond(string $id, int $userId, string $decision, string $requestId): OrganizationInvitation
    {
        $this->validator->make(['decision' => $decision], ['decision' => ['required', 'in:accepted,declined']], MembershipInput::messages())->validate();
        $initial = $this->invitations->find($id);
        if ($this->accounts->verifiedEmail($userId) !== $initial->email) {
            throw new ModelNotFoundException;
        }

        return $this->database->transaction(function () use ($id, $userId, $decision, $requestId, $initial): OrganizationInvitation {
            // Same account lock serializes admissions across DIFFERENT organizations.
            $email = $this->accounts->lockVerifiedEmail($userId);
            $organization = $this->organizations->lock($initial->organization_id);
            $invitation = $this->invitations->find($id, true);
            if ($email === null || $email !== $invitation->email) {
                throw new ModelNotFoundException;
            }
            $this->requirePending($invitation);
            $before = $invitation->attributesToArray();
            if ($decision === 'accepted') {
                $this->requireActive($organization->acceptsBusinessOperations());
                if ($organization->owner_user_id === $userId) {
                    throw new DomainConflictException(__('tenancy.errors.membership_exists'), 'MEMBERSHIP_ALREADY_EXISTS');
                }
                $existing = $this->memberships->forAccount($organization->id, $userId, true);
                if ($existing !== null && $existing->status !== MembershipStatus::Revoked
                    && ($existing->ends_at === null || $existing->ends_at->greaterThan(now()))) {
                    throw new DomainConflictException(__('tenancy.errors.membership_exists'), 'MEMBERSHIP_ALREADY_EXISTS');
                }
                $start = $invitation->starts_at->greaterThan(now()) ? $invitation->starts_at : CarbonImmutable::now();
                if ($invitation->ends_at !== null && $invitation->ends_at->lessThanOrEqualTo($start)) {
                    throw new DomainConflictException(__('tenancy.errors.invitation_period'), 'INVITATION_PERIOD_ENDED');
                }
                $this->admission->ensureAllowed($organization->id, $userId, $start, $invitation->ends_at);
                $oldMembership = $existing?->toArray();
                $membership = $this->memberships->save($existing, ['organization_id' => $organization->id, 'user_id' => $userId,
                    'roles' => $invitation->roles, 'status' => MembershipStatus::Active, 'starts_at' => $start, 'ends_at' => $invitation->ends_at]);
                $this->audit->record(['organization_id' => $organization->id, 'actor_user_id' => $userId,
                    'action' => 'membership.joined', 'subject_id' => $membership->id, 'before' => $oldMembership,
                    'after' => $membership->toArray(), 'request_id' => $requestId, 'occurred_at' => now()]);
            }
            $this->invitations->save($invitation, ['status' => InvitationStatus::from($decision), 'responded_by' => $userId, 'responded_at' => now()]);
            $this->record($invitation, $userId, $requestId, 'invitation.'.$decision, $before);

            return $invitation;
        });
    }

    public function revoke(TenantContext $context, string $id): OrganizationInvitation
    {
        return $this->database->transaction(function () use ($context, $id): OrganizationInvitation {
            $organization = $this->organizations->lock($context->organizationId);
            $this->requireActive($organization->acceptsBusinessOperations());
            $invitation = $this->invitations->find($id, true);
            $context->assertOrganization($invitation->organization_id);
            $this->access->requireRoleDelegation($organization, $context->actorUserId, $invitation->roles);
            $this->requirePending($invitation);
            $before = $invitation->attributesToArray();
            $this->invitations->save($invitation, ['status' => InvitationStatus::Revoked, 'responded_by' => $context->actorUserId, 'responded_at' => now()]);
            $this->record($invitation, $context->actorUserId, $context->requestId, 'invitation.revoked', $before);

            return $invitation;
        });
    }

    private function requirePending(OrganizationInvitation $invitation): void
    {
        if ($invitation->effectiveStatus(now()) === InvitationStatus::Expired) {
            throw new DomainConflictException(__('tenancy.errors.invitation_expired'), 'INVITATION_EXPIRED');
        }
        if ($invitation->status !== InvitationStatus::Pending) {
            throw new DomainConflictException(__('tenancy.errors.invitation_closed'), 'INVITATION_ALREADY_CLOSED');
        }
    }

    private function requireActive(bool $active): void
    {
        if (! $active) {
            throw new DomainConflictException(__('tenancy.errors.inactive'), 'ORGANIZATION_INACTIVE');
        }
    }

    /** @param array<string, mixed>|null $before */
    private function record(OrganizationInvitation $invitation, int $actorId, string $requestId, string $action, ?array $before): void
    {
        $after = $invitation->attributesToArray();
        $this->audit->record(['organization_id' => $invitation->organization_id, 'actor_user_id' => $actorId,
            'action' => $action, 'subject_id' => $invitation->id, 'before' => $before,
            'after' => $after, 'request_id' => $requestId, 'occurred_at' => now()]);
    }
}
