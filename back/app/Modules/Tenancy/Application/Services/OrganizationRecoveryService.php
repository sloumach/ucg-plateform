<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Contracts\TenantWorkRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Exceptions\InvalidOrganizationAccountException;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

final readonly class OrganizationRecoveryService
{
    public function __construct(
        private OrganizationPortabilityService $portability, private PortabilityRepository $operations,
        private OrganizationRepository $organizations, private TenantWorkRepository $work,
        private AccountDirectory $accounts, private MembershipAdmissionService $admission,
        private ConnectionInterface $database,
    ) {}

    /** Explicit unarchive of preserved data; never imports an archive or resumes business jobs automatically. */
    public function recover(string $organizationId, string $confirmation, string $sourceExport, OrganizationAuditData $audit): PortabilityOperation
    {
        if ($confirmation !== $organizationId) {
            throw new DomainConflictException(__('tenancy.portability.confirm'), 'PORTABILITY_CONFIRMATION_REQUIRED');
        }
        $stream = $this->portability->read($organizationId, $sourceExport, $audit);
        fclose($stream);
        $ownerId = $this->work->find($organizationId)->owner_user_id;

        return $this->database->transaction(function () use ($organizationId, $ownerId, $audit, $sourceExport): PortabilityOperation {
            // Same account-before-organization order as admissions; owner identity is immutable.
            if ($this->accounts->lockVerifiedEmail($ownerId) === null) {
                throw new InvalidOrganizationAccountException;
            }
            $organization = $this->portability->authorize($organizationId, $audit);
            $source = $this->operations->find($organizationId, $sourceExport);
            if ($organization->status !== OrganizationStatus::Archived || $this->operations->hasPurge($organizationId)
                || ($this->operations->counts($organizationId)['organization_settings'] ?? 0) !== 1
                || $source->kind !== 'export' || $source->status !== 'ready' || $source->expiresAt === null
                || CarbonImmutable::parse($source->expiresAt)->lessThanOrEqualTo(now())) {
                throw new DomainConflictException(__('tenancy.portability.recovery_blocked'), 'ORGANIZATION_RECOVERY_BLOCKED');
            }
            $this->admission->ensureAllowed($organizationId, $ownerId, now(), null);
            $operation = $this->operations->create($organizationId, ['actor_user_id' => $audit->actorUserId,
                'request_id' => $audit->requestId, 'kind' => 'recovery', 'status' => 'completed', 'reason' => $audit->reason,
                'details' => ['source_export' => $sourceExport, 'from_status' => 'archived', 'to_status' => 'suspended']]);
            $this->organizations->setStatus($organization, OrganizationStatus::Suspended);
            $this->organizations->recordLifecycleEvent(['organization_id' => $organizationId,
                'actor_user_id' => $audit->actorUserId, 'action' => 'status_changed', 'from_status' => 'archived',
                'to_status' => 'suspended', 'reason' => $audit->reason, 'request_id' => $audit->requestId, 'occurred_at' => now()]);

            return $operation;
        });
    }
}
