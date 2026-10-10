<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use Closure;
use Illuminate\Log\Context\Repository;

/** Scoped worker state; always restored, including exceptions and nested synchronous dispatch. */
final class TenantExecutionScope
{
    private ?TenantExecutionContext $current = null;

    public function __construct(private readonly Repository $logs) {}

    public function active(): bool
    {
        return $this->current !== null;
    }

    public function current(): TenantExecutionContext
    {
        return $this->current ?? throw new TenantContextRequiredException;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(TenantExecutionContext $context, Closure $operation): mixed
    {
        $previous = $this->current;
        $this->current = $context;
        try {
            return $this->logs->scope(function () use ($context, $operation): mixed {
                $this->logs->forget(['tenant_id', 'request_id', 'actor_user_id']);
                $this->logs->add(['tenant_id' => $context->organizationId, 'request_id' => $context->requestId,
                    'actor_user_id' => $context->actorContext?->actorUserId]);

                return $operation();
            });
        } finally {
            $this->current = $previous;
        }
    }
}
