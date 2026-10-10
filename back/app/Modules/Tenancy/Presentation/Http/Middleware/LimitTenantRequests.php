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
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class LimitTenantRequests
{
    private const int WINDOW_SECONDS = 60;

    private const int LOCK_SECONDS = 5;

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
        if (! $lock->get()) {
            return $this->refused($request, $maximum, 1);
        }
        try {
            if ($limiter->tooManyAttempts($key, $maximum)) {
                return $this->refused($request, $maximum, $limiter->availableIn($key));
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

    private function refused(Request $request, int $maximum, int $retryAfter): Response
    {
        return $this->responses->error($request, __('api.errors.rate_limit_exceeded'), ApiErrorCode::RateLimitExceeded, 429)
            ->withHeaders(['Retry-After' => (string) max(1, $retryAfter), 'X-RateLimit-Limit' => (string) $maximum, 'X-RateLimit-Remaining' => '0']);
    }
}
