<?php

namespace App\Modules\Tenancy\Presentation\Http\Middleware;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RequireTenantConfirmation
{
    public function __construct(private ActiveOrganizationSession $selection) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(TenantContext::ATTRIBUTE);
        if (! $context instanceof TenantContext) {
            throw new TenantContextRequiredException;
        }
        $this->selection->assertConfirmed($request, $context);

        return $next($request);
    }
}
