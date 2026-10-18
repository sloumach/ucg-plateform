<?php

namespace App\Modules\Competition\Presentation\Broadcasting;

use App\Modules\Competition\Application\Services\MatchRealtimeService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

final readonly class MatchScoreChannel
{
    public function __construct(private Request $request, private MatchRealtimeService $service) {}

    public function join(Authenticatable $user, string $tenantId, string $matchId): bool
    {
        $context = $this->request->attributes->get(TenantContext::ATTRIBUTE);
        if (! $context instanceof TenantContext || $context->organizationId !== $tenantId || $context->actorUserId !== (int) $user->getAuthIdentifier()
            || $this->request->route('match') !== $matchId) {
            return false;
        }
        $this->service->authorize($context, $matchId);

        return true;
    }
}
