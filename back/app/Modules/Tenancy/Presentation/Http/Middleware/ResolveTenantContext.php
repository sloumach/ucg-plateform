<?php

namespace App\Modules\Tenancy\Presentation\Http\Middleware;

use App\Http\Api\RequestId;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final readonly class ResolveTenantContext
{
    public function __construct(private TenantResolver $resolver, private ActiveOrganizationSession $selection) {}

    public function handle(Request $request, Closure $next, string $routeParameter = 'tenant'): Response
    {
        $request->attributes->remove(TenantContext::ATTRIBUTE);
        Context::forget('tenant_id');
        try {
            $user = $request->user();
            if ($user === null) {
                throw new AuthenticationException;
            }
            $routeOrganizationId = $request->route($routeParameter);
            if ($routeOrganizationId !== null && ! is_string($routeOrganizationId)) {
                throw new ModelNotFoundException;
            }
            $context = $this->resolver->resolve(
                $routeOrganizationId, $request->getHost(),
                (int) $user->getAuthIdentifier(), RequestId::for($request),
            );
            $request->attributes->set(TenantContext::ATTRIBUTE, $context);
            Context::add('tenant_id', $context->organizationId);
            $this->selection->assertCurrent($request, $context);

            return $next($request);
        } finally {
            $request->attributes->remove(TenantContext::ATTRIBUTE);
            Context::forget('tenant_id');
        }
    }
}
