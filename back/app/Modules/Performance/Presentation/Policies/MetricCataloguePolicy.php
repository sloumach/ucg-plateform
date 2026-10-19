<?php

namespace App\Modules\Performance\Presentation\Policies;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class MetricCataloguePolicy
{
    public function __construct(private AccountDirectory $accounts, private PlatformCatalogueGuard $guard) {}

    public function viewAny(Authenticatable $user): bool
    {
        return $this->accounts->verifiedAccountExists((int) $user->getAuthIdentifier());
    }

    public function manage(Authenticatable $user): bool
    {
        return $this->guard->allows((int) $user->getAuthIdentifier());
    }
}
