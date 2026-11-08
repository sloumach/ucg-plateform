<?php

namespace App\Modules\Teams\Presentation\Policies;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Tenancy\Application\Contracts\ContextualAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class GameCataloguePolicy
{
    public function __construct(private PlatformCatalogueGuard $platform, private AccountDirectory $accounts,
        private ContextualAuthorization $scoped) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->accounts->verifiedAccountExists((int) $user->getAuthIdentifier());
    }

    public function manage(Authenticatable $user): bool
    {
        return $this->platform->allows((int) $user->getAuthIdentifier());
    }

    public function viewTenant(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->scoped->any($context, 'game', 'sports.read');
    }

    public function manageTenant(Authenticatable $user, TenantContext $context): bool
    {
        return $this->viewTenant($user, $context) && $this->scoped->any($context, 'game', 'games.manage');
    }
}
