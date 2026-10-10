<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Validation\OrganizationInput;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityQueue;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Exceptions\InvalidOrganizationAccountException;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\Connection;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class OrganizationPortabilityService
{
    public function __construct(
        private OrganizationRepository $organizations, private PortabilityRepository $operations,
        private PortabilityArchive $archives, private PortabilityQueue $queue, private AccountDirectory $accounts,
        private Connection $database, private Factory $validator, private Repository $config,
        private TenantTechnicalLimits $limits, private TenantCacheStore $locks,
        private TenantExecutionScope $scope, private LoggerInterface $logger, private Application $app,
    ) {}

    /** Operator-server entry point only; declaring an actor does not grant a platform role. */
    public function authorize(string $organizationId, OrganizationAuditData $audit): Organization
    {
        $this->validator->make(['organization' => $organizationId, 'actor' => $audit->actorUserId,
            'reason' => $audit->reason, 'request_id' => $audit->requestId], [
                'organization' => ['required', 'uuid'], 'actor' => ['required', 'integer', 'min:1'],
                'reason' => ['required', 'string', 'max:1000', 'regex:/\S/u'], 'request_id' => ['required', 'uuid'],
            ], OrganizationInput::messages())->validate();
        if (! $this->accounts->verifiedAccountExists($audit->actorUserId)) {
            throw new InvalidOrganizationAccountException;
        }

        return $this->organizations->lock(strtolower($organizationId));
    }

    public function requestExport(string $organizationId, OrganizationAuditData $audit): PortabilityOperation
    {
        $operation = $this->database->transaction(function () use ($organizationId, $audit): PortabilityOperation {
            $organization = $this->authorize($organizationId, $audit);
            $this->operations->assertCatalog();
            $this->assertNoPurge($organization->id);
            $disk = $this->config->get('filesystems.artifacts');
            if (! is_string($disk)) {
                throw new LogicException('A private export disk must be configured.');
            }
            $this->archives->validateDisk($disk);
            $bytes = $this->setting('export_bytes', 1, 1073741824);
            $capacity = $this->setting('export_storage_bytes', $bytes, 2147483647);
            if ($this->operations->reservedExportBytes($organization->id) > $capacity - $bytes) {
                throw new TenantLimitExceededException;
            }

            return $this->operations->create($organization->id, ['actor_user_id' => $audit->actorUserId,
                'request_id' => $audit->requestId, 'kind' => 'export', 'status' => 'pending', 'reason' => $audit->reason,
                'disk' => $disk, 'bytes' => $bytes,
                'expires_at' => now()->addHours($this->setting('export_lifetime_hours', 1, 168)),
                'details' => ['reserved_bytes' => $bytes]]);
        });
        // A transport failure retains a visible pending operation, safely re-dispatchable by UUID.
        try {
            $this->queue->export($operation->organizationId, $operation->id);
        } catch (Throwable $exception) {
            $this->logger->warning('tenant.export.enqueue_failed', ['tenant_id' => $operation->organizationId,
                'operation_id' => $operation->id, 'request_id' => $operation->requestId, 'exception_type' => $exception::class]);
            throw $exception;
        }

        return $operation;
    }

    public function retryExport(string $organizationId, string $operationId, OrganizationAuditData $audit): void
    {
        $this->database->transaction(function () use ($organizationId, $operationId, $audit): void {
            $this->authorize($organizationId, $audit);
            $this->assertNoPurge($organizationId);
            $operation = $this->operations->find($organizationId, $operationId);
            $this->assertLive($operation);
        });
        $this->queue->export($organizationId, $operationId);
    }

    public function generate(string $organizationId, string $operationId): void
    {
        if ($this->database->transactionLevel() !== 0 && ! $this->app->runningUnitTests()) {
            throw new LogicException('Export generation requires its own bounded snapshot transaction.');
        }
        $lock = $this->locks->lock(TenantResourceNamespace::key($organizationId, 'lock', 'portability'), 90);
        if (! $lock->get()) {
            throw new DomainConflictException(__('tenancy.portability.busy'), 'PORTABILITY_BUSY');
        }
        try {
            $this->database->transaction(function () use ($organizationId, $operationId): void {
                if ($this->database->getDriverName() === 'pgsql' && $this->database->transactionLevel() === 1) {
                    $this->database->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                }
                $operation = $this->operations->find($organizationId, $operationId);
                $organization = $this->authorize($organizationId,
                    new OrganizationAuditData($operation->actorUserId, $operation->reason, $operation->requestId));
                $this->assertNoPurge($organizationId);
                $this->assertLive($operation);
                if ($operation->status === 'ready') {
                    return;
                }
                $this->scope->run(new TenantExecutionContext($organization->id, $operation->requestId,
                    $organization->timezone, $organization->language), function () use ($operation, $organization): void {
                        $result = $this->archives->build($operation,
                            $this->operations->rows($operation->organizationId, $this->limits->valueForOrganization($operation->organizationId, 'export_rows')),
                            $operation->bytes);
                        $this->assertLive($operation);
                        $this->operations->update($operation->organizationId, $operation->id, [
                            'status' => 'ready', 'bytes' => $result->bytes, 'sha256' => $result->sha256,
                            'details' => ['rows' => $result->rows, 'files' => $result->files, 'format' => 'ucg-tenant-ndjson-v1',
                                'organization_status' => $organization->status->value,
                                'organization_updated_at' => $organization->updated_at?->format('Y-m-d\TH:i:s.uP')],
                        ]);
                        $this->logger->info('tenant.export.ready', ['operation_id' => $operation->id, 'rows' => $result->rows, 'files' => $result->files]);
                    });
            });
        } finally {
            $lock->release();
        }
    }

    public function inspect(string $organizationId, string $operationId, OrganizationAuditData $audit): PortabilityOperation
    {
        return $this->database->transaction(function () use ($organizationId, $operationId, $audit): PortabilityOperation {
            $this->authorize($organizationId, $audit);

            return $this->operations->find($organizationId, $operationId);
        });
    }

    /** @return resource */
    public function read(string $organizationId, string $operationId, OrganizationAuditData $audit)
    {
        $operation = $this->inspect($organizationId, $operationId, $audit);
        $this->assertLive($operation);
        if ($operation->status !== 'ready') {
            throw new DomainConflictException(__('tenancy.portability.not_ready'), 'EXPORT_NOT_READY');
        }
        $stream = $this->archives->read($operation);
        try {
            $this->assertLive($operation);
        } catch (Throwable $exception) {
            fclose($stream);
            throw $exception;
        }
        $this->logger->info('tenant.export.read', ['tenant_id' => $operation->organizationId,
            'operation_id' => $operation->id, 'request_id' => $audit->requestId, 'operator_actor_id' => $audit->actorUserId]);

        return $stream;
    }

    public function expire(string $organizationId, string $operationId): void
    {
        $lock = $this->locks->lock(TenantResourceNamespace::key($organizationId, 'lock', 'portability'), 90);
        if (! $lock->get()) {
            throw new DomainConflictException(__('tenancy.portability.busy'), 'PORTABILITY_BUSY');
        }
        try {
            $operation = $this->operations->find($organizationId, $operationId);
            if ($operation->kind !== 'export' || $operation->expiresAt === null || CarbonImmutable::parse($operation->expiresAt)->isFuture()) {
                throw new DomainConflictException(__('tenancy.portability.not_expired'), 'EXPORT_NOT_EXPIRED');
            }
            if ($operation->status === 'expired') {
                return;
            }
            $this->archives->delete($operation);
            $this->operations->update($organizationId, $operationId, ['status' => 'expired', 'bytes' => 0]);
            $this->logger->info('tenant.export.expired', ['tenant_id' => $organizationId,
                'operation_id' => $operationId, 'request_id' => $operation->requestId]);
        } finally {
            $lock->release();
        }
    }

    public function expireBatch(int $limit = 100): int
    {
        $failures = 0;
        $started = hrtime(true);
        foreach ($this->operations->expiredExports($limit) as $operation) {
            if (hrtime(true) - $started > 45_000_000_000) {
                break;
            }
            try {
                $this->expire($operation->organizationId, $operation->id);
            } catch (Throwable $exception) {
                $failures++;
                // Rotate failed storage attempts behind older candidates; never alter the expiry date.
                if (! ($exception instanceof DomainConflictException && $exception->errorCode() === 'PORTABILITY_BUSY')) {
                    $this->operations->update($operation->organizationId, $operation->id, []);
                }
                $this->logger->warning('tenant.export.expiry_failed', ['tenant_id' => $operation->organizationId,
                    'operation_id' => $operation->id, 'exception_type' => $exception::class]);
            }
        }

        return $failures;
    }

    public function failed(string $organizationId, string $operationId): void
    {
        $operation = $this->operations->find($organizationId, $operationId);
        if ($operation->kind === 'export' && in_array($operation->status, ['pending', 'failed'], true)) {
            $this->operations->update($organizationId, $operationId, ['status' => 'failed']);
        }
    }

    private function assertLive(PortabilityOperation $operation): void
    {
        if ($operation->kind !== 'export' || $operation->status === 'expired'
            || $operation->expiresAt === null || CarbonImmutable::parse($operation->expiresAt)->lessThanOrEqualTo(now())) {
            throw new DomainConflictException(__('tenancy.portability.expired'), 'EXPORT_EXPIRED');
        }
    }

    private function assertNoPurge(string $organizationId): void
    {
        if ($this->operations->hasPurge($organizationId)) {
            throw new DomainConflictException(__('tenancy.portability.purge_started'), 'PURGE_ALREADY_STARTED');
        }
    }

    private function setting(string $name, int $min, int $max): int
    {
        $value = $this->config->get('tenancy.portability.'.$name);
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new LogicException('Invalid bounded portability configuration: '.$name);
        }

        return $value;
    }
}
