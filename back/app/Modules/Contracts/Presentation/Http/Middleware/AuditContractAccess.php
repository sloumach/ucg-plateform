<?php

namespace App\Modules\Contracts\Presentation\Http\Middleware;

use App\Exceptions\SensitiveAuditUnavailableException;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantSensitiveAccessJournal;
use App\Support\Audit\SensitiveAccessAction;
use App\Support\Audit\SensitiveAccessData;
use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final readonly class AuditContractAccess
{
    public function __construct(private TenantSensitiveAccessJournal $journal, private Factory $validator, private LoggerInterface $logger) {}

    public function handle(Request $request, Closure $next): Response
    {
        $action = SensitiveAccessAction::forRoute((string) $request->route()?->getName());
        if ($action === null) {
            return $next($request);
        }
        // This optional header never enters business payload validation or the signed download URL.
        $values = $this->validator->make(['access_purpose' => $request->header('X-Access-Purpose')], [
            'access_purpose' => ['nullable', 'string', 'in:operational,internal_control,legal_review'],
        ], ['*.*' => __('audit.access_purpose_invalid')])->validate();
        $response = $next($request);
        if (! $response->isSuccessful()) {
            return $response;
        }
        $context = $request->attributes->get(TenantContext::ATTRIBUTE);
        if (! $context instanceof TenantContext || $context->actorUserId !== (int) $request->user()?->getAuthIdentifier()) {
            throw new \LogicException('Audited reads require a resolved tenant actor.');
        }
        $parameter = $action->routeParameter();
        $target = $parameter === null ? $context->organizationId : (string) $request->route($parameter);
        try {
            $this->journal->record($context->organizationId, new SensitiveAccessData($context->actorUserId,
                $action, $target, $context->requestId, $values['access_purpose'] ?? 'operational', $request->ip()));
        } catch (Throwable $exception) {
            $this->logger->error('contract.access_audit_failed', ['organization_id' => $context->organizationId,
                'request_id' => $context->requestId, 'exception_type' => $exception::class]);
            // The response has been built but has not left the middleware pipeline.
            throw new SensitiveAuditUnavailableException;
        }

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
