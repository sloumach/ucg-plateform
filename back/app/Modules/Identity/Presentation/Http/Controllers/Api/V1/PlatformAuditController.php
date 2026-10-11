<?php

namespace App\Modules\Identity\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEntryResource;
use App\Modules\Identity\Application\Services\PlatformAuditService;
use App\Modules\Identity\Domain\Models\IdentityAuditEvent;
use App\Modules\Identity\Presentation\Http\Requests\Api\V1\PlatformAuditRequest;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlatformAuditController extends Controller
{
    public function __construct(private readonly PlatformAuditService $audit, private readonly Gate $gate) {}

    public function index(PlatformAuditRequest $request): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', IdentityAuditEvent::class);

        return AuditEntryResource::page($this->audit->listing($request->toFilter(), RequestId::for($request), $request->ip()), $request);
    }
}
