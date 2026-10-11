<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Contracts\TenantWorkRepository;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use App\Support\Queue\JobContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class TenantJobRunner
{
    public function __construct(
        private TenantResolver $resolver, private TenantWorkRepository $organizations,
        private TenantExecutionScope $scope, private LoggerInterface $logger,
        private AccountDirectory $accounts,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function user(JobContext $transport, Closure $operation): mixed
    {
        $this->validate($transport);
        $actorUserId = $transport->actorUserId ?? null;
        if ($actorUserId === null || $actorUserId < 1) {
            throw new TenantExecutionRejectedException('ACTOR_REQUIRED');
        }
        try {
            $actor = $this->resolver->resolve($transport->tenantId, '', $actorUserId, $transport->requestId);
        } catch (ModelNotFoundException) {
            throw new TenantExecutionRejectedException('TENANT_ACCESS_REVOKED');
        } catch (DomainConflictException $exception) {
            throw new TenantExecutionRejectedException($exception->errorCode());
        }

        if ($actor->canAdminister() && ! $this->accounts->hasConfirmedMfa($actorUserId)) {
            throw new TenantExecutionRejectedException('MFA_ENROLLMENT_REQUIRED');
        }

        return $this->execute(new TenantExecutionContext($actor->organizationId, $actor->requestId,
            $actor->timezone, $actor->language, $actor), $operation);
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function scheduled(JobContext $transport, Closure $operation): mixed
    {
        $this->validate($transport);
        if (($transport->actorUserId ?? null) !== null) {
            throw new TenantExecutionRejectedException('SYSTEM_TASK_ACTOR_FORBIDDEN');
        }
        try {
            $organization = $this->organizations->find((string) $transport->tenantId);
        } catch (ModelNotFoundException) {
            throw new TenantExecutionRejectedException('TENANT_NOT_FOUND');
        }
        if (! $organization->acceptsBusinessOperations()) {
            throw new TenantExecutionRejectedException('ORGANIZATION_INACTIVE');
        }

        return $this->execute(new TenantExecutionContext($organization->id, $transport->requestId,
            $organization->timezone, $organization->language), $operation);
    }

    private function validate(JobContext $transport): void
    {
        if ($transport->tenantId === null || ! Str::isUuid($transport->tenantId) || ! Str::isUuid($transport->requestId)) {
            throw new TenantExecutionRejectedException('INVALID_TENANT_JOB_CONTEXT');
        }
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function execute(TenantExecutionContext $context, Closure $operation): mixed
    {
        return $this->scope->run($context, function () use ($operation): mixed {
            $this->logger->info('tenant.work.started');
            try {
                $result = $operation();
                $this->logger->info('tenant.work.handled');

                return $result;
            } catch (Throwable $exception) {
                $this->logger->warning('tenant.work.failed', ['exception_type' => $exception::class]);
                throw $exception;
            }
        });
    }
}
