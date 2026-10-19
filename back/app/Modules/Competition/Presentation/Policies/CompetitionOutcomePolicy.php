<?php

namespace App\Modules\Competition\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class CompetitionOutcomePolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function viewAny(Authenticatable $user, TenantContext $context, string $stream): bool
    {
        if ((int) $user->getAuthIdentifier() !== $context->actorUserId || ! in_array($stream, ['sport', 'awards'], true)) {
            return false;
        }
        foreach (['sports.read', 'sports.competitions.read', ...($stream === 'awards' ? ['financial.read'] : [])] as $p) {
            if (! $this->authorization->allows($context, $p)) {
                return false;
            }
        }

        return true;
    }

    public function manage(Authenticatable $user, TenantContext $context, string $stream): bool
    {
        return $this->viewAny($user, $context, $stream) && $this->authorization->allows($context, $stream === 'awards' ? 'financial.write' : 'sports.competitions.write');
    }
}
