<?php

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Modules\Tenancy\Application\Contracts\TenantJob;
use App\Modules\Tenancy\Application\Contracts\TenantJobMiddleware;
use App\Modules\Tenancy\Application\Contracts\TenantQueuedWork;
use App\Modules\Tenancy\Application\Services\TenantJobRunner;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Log\Context\Repository;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Str;
use LogicException;
use Psr\Log\LoggerInterface;

final readonly class RestoreTenantJobContext implements TenantJobMiddleware
{
    public function __construct(private TenantJobRunner $runner, private LoggerInterface $logger, private Repository $logs) {}

    /** @param Closure(TenantJob|SendQueuedMailable): mixed $next */
    public function handle(TenantJob|SendQueuedMailable $job, Closure $next): mixed
    {
        $work = $job instanceof SendQueuedMailable ? $job->mailable : $job;
        if (! $work instanceof TenantQueuedWork) {
            throw new LogicException('Tenant queue middleware requires an explicit transport contract.');
        }
        try {
            return $job instanceof RunTenantScheduledTask
                ? $this->runner->scheduled($work->jobContext(), fn () => $next($job))
                : $this->runner->user($work->jobContext(), fn () => $next($job));
        } catch (TenantExecutionRejectedException $exception) {
            $transport = $work->jobContext();
            $this->logs->scope(fn () => $this->logger->warning('tenant.work.rejected',
                ['reason' => $exception->reason, 'job_type' => $work::class, 'attempt' => $job->attempts()]),
                ['tenant_id' => $transport->tenantId !== null && Str::isUuid($transport->tenantId) ? strtolower($transport->tenantId) : null,
                    'request_id' => Str::isUuid($transport->requestId) ? $transport->requestId : null, 'actor_user_id' => null]);
            $job->fail($exception);

            return null;
        } catch (AuthorizationException) {
            $job->fail(new TenantExecutionRejectedException('TENANT_ACTION_FORBIDDEN'));

            return null;
        }
    }
}
