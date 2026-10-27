<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Domain\Contracts\ArtifactLedger;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

final readonly class OrganizationPurgeService
{
    private const array CATEGORIES = ['settings', 'artifacts', 'exports', 'access_history', 'lifecycle_history', 'organization_identity'];

    public function __construct(
        private OrganizationPortabilityService $portability, private PortabilityRepository $operations,
        private PortabilityArchive $archives, private ArtifactLedger $ledger, private Repository $config,
        private ConnectionInterface $database, private TenantCacheStore $locks, private LoggerInterface $logger,
        private RetentionGuard $retention,
    ) {}

    /**
     * Read-only inventory: absent or incomplete retention policy never authorizes deletion.
     *
     * @return array<string, mixed>
     */
    public function plan(string $organizationId, OrganizationAuditData $audit): array
    {
        return $this->database->transaction(function () use ($organizationId, $audit): array {
            $organization = $this->portability->authorize($organizationId, $audit);

            return ['organization_id' => $organization->id, 'status' => $organization->status->value,
                'counts' => $this->operations->counts($organization->id), 'policy_required' => true,
                'protected' => ['global_accounts', 'access_history', 'lifecycle_history', 'organization_identity']];
        });
    }

    /** One restartable, bounded operator batch. Protected histories are never disabled or rewritten. */
    public function execute(string $organizationId, string $confirmation, string $sourceExport, OrganizationAuditData $audit, ?string $operationId = null): PortabilityOperation
    {
        if ($organizationId !== $confirmation) {
            throw new DomainConflictException(__('tenancy.portability.confirm'), 'PORTABILITY_CONFIRMATION_REQUIRED');
        }
        $policy = $this->policy($organizationId);
        $policyHash = hash('sha256', json_encode($policy, JSON_THROW_ON_ERROR));
        // CLI batch: allow the final bounded S3 HEAD/delete calls to finish after the soft deadline.
        $lock = $this->locks->lock(TenantResourceNamespace::key($organizationId, 'lock', 'portability'), 180);
        if (! $lock->get()) {
            throw new DomainConflictException(__('tenancy.portability.busy'), 'PORTABILITY_BUSY');
        }
        try {
            if ($operationId === null) {
                $stream = $this->portability->read($organizationId, $sourceExport, $audit);
                fclose($stream);
            }
            $operation = $this->database->transaction(function () use ($organizationId, $operationId, $sourceExport, $audit, $policy, $policyHash): PortabilityOperation {
                $organization = $this->portability->authorize($organizationId, $audit);
                $this->retention->assertRemovalAllowed($organizationId);
                $this->operations->assertCatalog();
                if ($organization->status !== OrganizationStatus::Archived) {
                    throw new DomainConflictException(__('tenancy.portability.archived_required'), 'PURGE_ARCHIVED_REQUIRED');
                }
                if ($operationId !== null) {
                    $existing = $this->operations->find($organizationId, $operationId);
                    if ($existing->kind !== 'purge' || $existing->sha256 !== $policyHash || ($existing->details['source_export'] ?? null) !== $sourceExport) {
                        throw new DomainConflictException(__('tenancy.portability.policy_changed'), 'PURGE_POLICY_CHANGED');
                    }

                    return $existing;
                }
                if ($this->operations->hasPurge($organizationId)) {
                    throw new DomainConflictException(__('tenancy.portability.purge_started'), 'PURGE_ALREADY_STARTED');
                }
                $source = $this->operations->find($organizationId, $sourceExport);
                if ($source->status !== 'ready' || $source->expiresAt === null
                    || CarbonImmutable::parse($source->expiresAt)->lessThanOrEqualTo(now())
                    || ($source->details['organization_status'] ?? null) !== 'archived'
                    || ($source->details['organization_updated_at'] ?? null) !== $organization->updated_at?->format('Y-m-d\TH:i:s.uP')) {
                    throw new DomainConflictException(__('tenancy.portability.snapshot_required'), 'PURGE_ARCHIVED_EXPORT_REQUIRED');
                }

                return $this->operations->create($organizationId, ['actor_user_id' => $audit->actorUserId,
                    'request_id' => $audit->requestId, 'kind' => 'purge', 'status' => 'pending', 'reason' => $audit->reason,
                    'sha256' => $policyHash, 'details' => ['policy_reference' => $policy['reference'],
                        'source_export' => $sourceExport, 'policy' => $policy, 'categories' => $policy['categories']]]);
            });
            if ($operation->status === 'completed') {
                return $operation;
            }
            $categories = $policy['categories'];
            $started = hrtime(true);
            if ($categories['artifacts'] === 'delete') {
                foreach ($this->operations->artifacts($organizationId, 100) as $artifact) {
                    if (hrtime(true) - $started > 45_000_000_000) {
                        break;
                    }
                    $this->ledger->locked($organizationId, $artifact['id'], function () use ($organizationId, $artifact): void {
                        $this->archives->deleteArtifact($organizationId, $artifact);
                        // Release accounting only after confirmed external deletion; safe after a partial failure.
                        $this->ledger->release($organizationId, $artifact['id']);
                    });
                }
            }
            if ($categories['exports'] === 'delete') {
                foreach ($this->operations->exports($organizationId, 100) as $export) {
                    if (hrtime(true) - $started > 45_000_000_000) {
                        break;
                    }
                    $this->archives->delete($export);
                    $this->operations->update($organizationId, $export->id, ['status' => 'expired', 'bytes' => 0]);
                }
            }
            $this->retention->destructive($organizationId, function () use ($organizationId, $audit, $categories): void {
                $this->portability->authorize($organizationId, $audit);
                if ($categories['settings'] === 'delete') {
                    $this->operations->removeSettings($organizationId);
                }
            });
            $more = ($categories['artifacts'] === 'delete' && $this->operations->artifacts($organizationId, 1) !== [])
                || ($categories['exports'] === 'delete' && $this->operations->exports($organizationId, 1) !== []);
            $this->operations->update($organizationId, $operation->id, ['status' => $more ? 'pending' : 'completed']);
            $this->logger->info('tenant.purge.batch', ['tenant_id' => $organizationId,
                'operation_id' => $operation->id, 'request_id' => $operation->requestId, 'remaining' => $more,
                'policy_reference' => $policy['reference']]);

            return $this->operations->find($organizationId, $operation->id);
        } finally {
            $lock->release();
        }
    }

    /** @return array{reference: string, not_before: string, backups_procedure: string, categories: array<string, string>} */
    private function policy(string $organizationId): array
    {
        $value = $this->config->get('tenancy.portability.purge_policies.'.strtolower($organizationId));
        if (! is_array($value) || array_diff(array_keys($value), ['reference', 'not_before', 'backups_procedure', 'categories']) !== []
            || ! is_string($value['reference'] ?? null) || trim($value['reference']) === '' || strlen($value['reference']) > 200
            || ! is_string($value['backups_procedure'] ?? null) || trim($value['backups_procedure']) === '' || strlen($value['backups_procedure']) > 200
            || ! is_string($value['not_before'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value['not_before'])
            || ! is_array($value['categories'] ?? null) || count($value['categories']) !== count(self::CATEGORIES)) {
            throw new DomainConflictException(__('tenancy.portability.policy_required'), 'RETENTION_POLICY_REQUIRED');
        }
        $categories = [];
        foreach (self::CATEGORIES as $category) {
            $choice = $value['categories'][$category] ?? null;
            if (! in_array($choice, ['delete', 'retain'], true)
                || (in_array($category, ['access_history', 'lifecycle_history', 'organization_identity'], true) && $choice !== 'retain')) {
                throw new DomainConflictException(__('tenancy.portability.policy_required'), 'RETENTION_POLICY_REQUIRED');
            }
            $categories[$category] = $choice;
        }
        try {
            $notBefore = CarbonImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value['not_before']);
        } catch (\Throwable) {
            throw new DomainConflictException(__('tenancy.portability.policy_required'), 'RETENTION_POLICY_REQUIRED');
        }
        if ($notBefore === null || $notBefore->format('Y-m-d\TH:i:sP') !== $value['not_before']) {
            throw new DomainConflictException(__('tenancy.portability.policy_required'), 'RETENTION_POLICY_REQUIRED');
        }
        if ($notBefore->isFuture()) {
            throw new DomainConflictException(__('tenancy.portability.retention_pending'), 'RETENTION_PERIOD_PENDING');
        }

        return ['reference' => $value['reference'], 'not_before' => $value['not_before'],
            'backups_procedure' => $value['backups_procedure'], 'categories' => $categories];
    }
}
