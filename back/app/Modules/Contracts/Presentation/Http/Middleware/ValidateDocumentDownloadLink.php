<?php

namespace App\Modules\Contracts\Presentation\Http\Middleware;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Symfony\Component\HttpFoundation\Response;

final readonly class ValidateDocumentDownloadLink
{
    public function __construct(private UrlGenerator $urls) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $context = $request->attributes->get(TenantContext::ATTRIBUTE);
        if (! $context instanceof TenantContext || $request->query('actor') !== (string) $context->actorUserId
            || ! $this->urls->hasValidRelativeSignature($request)
            || (int) $request->query('expires', 0) <= CarbonImmutable::now()->getTimestamp()) {
            throw new DomainConflictException(__('document_security.invalid_link'), 'CONTRACT_DOCUMENT_LINK_INVALID');
        }

        return $next($request);
    }
}
