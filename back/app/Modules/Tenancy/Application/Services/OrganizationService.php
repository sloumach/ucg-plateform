<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Exceptions\InvalidOrganizationAccountException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class OrganizationService
{
    public function __construct(
        private OrganizationRepository $organizations,
        private AccountDirectory $accounts,
        private ConnectionInterface $database,
        private Factory $validator,
        private OrganizationAccessService $access,
        private MembershipAdmissionService $admission,
    ) {}

    /** @return LengthAwarePaginator<int, Organization> */
    public function listOwned(int $ownerId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->validator->make(['per_page' => $perPage, 'page' => $page], [
            'per_page' => ['integer', 'between:1,100'], 'page' => ['integer', 'between:1,100000'],
        ], OrganizationInput::messages())->validate();

        return $this->organizations->paginateOwned($ownerId, $perPage, $page);
    }

    public function findOwned(string $id, int $ownerId): Organization
    {
        return $this->organizations->findOwned($id, $ownerId);
    }

    /** @return LengthAwarePaginator<int, Organization> */
    public function listAccessible(int $actorUserId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->validator->make(['per_page' => $perPage, 'page' => $page], [
            'per_page' => ['integer', 'between:1,100'], 'page' => ['integer', 'between:1,100000'],
        ], OrganizationInput::messages())->validate();

        return $this->organizations->paginateAccessible($actorUserId, $perPage, $page);
    }

    public function create(string $slug, int $ownerId, OrganizationDetailsData $details, OrganizationAuditData $audit): Organization
    {
        $this->validateDetails($details);
        $this->validateAudit($audit);
        $this->requireVerifiedAccount($ownerId);
        $this->validator->make(['slug' => $slug], [
            'slug' => ['required', 'string', 'max:80', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/'],
        ], OrganizationInput::messages())->validate();
        try {
            return $this->database->transaction(function () use ($slug, $ownerId, $details, $audit): Organization {
                if ($this->accounts->lockVerifiedEmail($ownerId) === null) {
                    throw new InvalidOrganizationAccountException;
                }
                $this->admission->ensureAllowed(null, $ownerId, now(), null);
                $organization = $this->organizations->create([
                    ...$details->attributes(), 'slug' => $slug, 'owner_user_id' => $ownerId, 'status' => OrganizationStatus::Active,
                ], $details->settings());
                $this->audit($organization, null, $audit);

                return $organization;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if ($this->organizations->findBySlug($slug) !== null) {
                throw new DomainConflictException(__('tenancy.errors.slug_taken'), 'ORGANIZATION_SLUG_TAKEN');
            }
            throw $exception;
        }
    }

    public function ensurePilot(int $ownerId, OrganizationDetailsData $details, OrganizationAuditData $audit): Organization
    {
        $this->requireVerifiedAccount($ownerId);
        $organization = $this->organizations->findBySlug('ucg');
        if ($organization !== null) {
            if ($organization->owner_user_id !== $ownerId) {
                throw new DomainConflictException(__('tenancy.errors.pilot_owner'), 'ORGANIZATION_PILOT_OWNER_CONFLICT');
            }

            return $organization;
        }

        return $this->create('ucg', $ownerId, $details, $audit);
    }

    public function updateInContext(TenantContext $context, OrganizationDetailsData $details): Organization
    {
        $this->validateDetails($details);

        return $this->database->transaction(function () use ($context, $details): Organization {
            $organization = $this->organizations->lock($context->organizationId);
            $this->access->find($organization->id, $context->actorUserId);
            $this->access->requireAdministration($organization, $context->actorUserId);
            if (! $organization->acceptsBusinessOperations()) {
                throw new DomainConflictException(__('tenancy.errors.inactive'), 'ORGANIZATION_INACTIVE');
            }

            return $this->organizations->updateDetails($organization, $details->attributes(), $details->settings());
        });
    }

    /** Trusted operator entry point; not exposed over HTTP before platform permissions exist. */
    public function transition(string $id, OrganizationStatus $target, OrganizationAuditData $audit): Organization
    {
        $this->validateAudit($audit);

        return $this->database->transaction(function () use ($id, $target, $audit): Organization {
            $organization = $this->organizations->lock($id);
            $previous = $organization->status;
            if (! $previous->canTransitionTo($target)) {
                throw new DomainConflictException(__('tenancy.errors.invalid_transition'), 'ORGANIZATION_TRANSITION_DENIED');
            }
            $this->organizations->setStatus($organization, $target);
            $this->audit($organization, $previous, $audit);

            return $organization->load('settings');
        });
    }

    private function validateDetails(OrganizationDetailsData $details): void
    {
        $this->validator->make([...$details->attributes(), 'settings' => $details->settings()],
            OrganizationInput::detailsRules(), OrganizationInput::messages())->validate();
    }

    private function validateAudit(OrganizationAuditData $audit): void
    {
        $this->validator->make(['reason' => $audit->reason, 'request_id' => $audit->requestId], [
            'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'],
            'request_id' => ['required', 'uuid'],
        ], OrganizationInput::messages())->validate();
        $this->requireVerifiedAccount($audit->actorUserId);
    }

    private function requireVerifiedAccount(int $userId): void
    {
        if (! $this->accounts->verifiedAccountExists($userId)) {
            throw new InvalidOrganizationAccountException;
        }
    }

    private function audit(Organization $organization, ?OrganizationStatus $previous, OrganizationAuditData $audit): void
    {
        $this->organizations->recordLifecycleEvent([
            'organization_id' => $organization->id,
            'actor_user_id' => $audit->actorUserId,
            'action' => $previous === null ? 'created' : 'status_changed',
            'from_status' => $previous?->value,
            'to_status' => $organization->status->value,
            'reason' => $audit->reason,
            'request_id' => $audit->requestId,
            'occurred_at' => now(),
        ]);
    }
}
