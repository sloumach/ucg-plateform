<?php

namespace App\Modules\Tenancy\Presentation\Http\Middleware;

use App\Http\Api\ApiErrorCode;
use App\Http\Api\ApiResponseFactory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantTechnicalLimits;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;
use Symfony\Component\HttpFoundation\Response;

final readonly class LimitTenantRequests
{
    public const string REFUSAL_ATTRIBUTE = 'tenant.rate_limit_refusal';

    private const int WINDOW_SECONDS = 60;

    private const int LOCK_SECONDS = 5;

    private const int LOCK_ATTEMPTS = 11;

    private const int LOCK_RETRY_MICROSECONDS = 5000;

    public function __construct(
        private TenantCacheStore $store,
        private TenantTechnicalLimits $limits, private ApiResponseFactory $responses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(TenantContext::ATTRIBUTE);
        if (! $context instanceof TenantContext || $context->actorUserId !== (int) $request->user()?->getAuthIdentifier()) {
            throw new TenantContextRequiredException;
        }
        $cache = $this->store->repository();
        $limiter = new RateLimiter($cache);
        $key = TenantResourceNamespace::key($context->organizationId, 'rate', 'http');
        $maximum = $this->limits->value($context, 'requests_per_minute');
        $lock = $this->store->lock(TenantResourceNamespace::key($context->organizationId, 'lock', 'http-rate'), self::LOCK_SECONDS);
        if (! $this->acquire($lock)) {
            return $this->refused($request, $maximum, 1, 'contention');
        }
        try {
            if ($limiter->tooManyAttempts($key, $maximum)) {
                return $this->refused($request, $maximum, $limiter->availableIn($key), 'quota');
            }
            $limiter->hit($key, self::WINDOW_SECONDS);
        } finally {
            $lock->release();
        }
        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $maximum);
        $response->headers->set('X-RateLimit-Remaining', (string) $limiter->remaining($key, $maximum));

        return $response;
    }

    private function acquire(Lock $lock): bool
    {
        for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
            if ($lock->get()) {
                return true;
            }
            if ($attempt < self::LOCK_ATTEMPTS - 1) {
                Sleep::usleep(self::LOCK_RETRY_MICROSECONDS);
            }
        }

        return false;
    }

    private function refused(Request $request, int $maximum, int $retryAfter, string $reason): Response
    {
        $request->attributes->set(self::REFUSAL_ATTRIBUTE, $reason);

        return $this->responses->error($request, __('api.errors.rate_limit_exceeded'), ApiErrorCode::RateLimitExceeded, 429)
            ->withHeaders(['Retry-After' => (string) max(1, $retryAfter), 'X-RateLimit-Limit' => (string) $maximum, 'X-RateLimit-Remaining' => '0']);
    }
}
