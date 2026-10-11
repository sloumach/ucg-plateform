<?php

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Http\Api\RequestId;
use App\Modules\Identity\Application\Contracts\PlatformAuditJournal;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

final readonly class AuditAccessFailures
{
    public function __construct(private PlatformAuditJournal $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $route = $request->route();
        if (in_array($response->getStatusCode(), [403, 404], true) && $request->user() !== null && $route instanceof Route && $route->getName() !== null) {
            $this->audit->record(new AuditWriteData((int) $request->user()->getAuthIdentifier(), AuditAction::AccessDenied,
                AuditTarget::Route, $route->getName(), AuditReason::Refused, RequestId::for($request), $request->ip(),
                after: ['http_status' => $response->getStatusCode()]));
        }

        return $response;
    }
}
