<?php

namespace App\Modules\Tenancy\Presentation\Policies;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class SupportAccessPolicy
{
    public function __construct(private PlatformAccountDirectory $platform, private AccountDirectory $accounts) {}

    public function operate(Authenticatable $user): bool
    {
        $id = (int) $user->getAuthIdentifier();

        return $this->platform->isTechnical($id) && $this->accounts->verifiedAccountExists($id) && $this->accounts->hasConfirmedMfa($id);
    }
}
