<?php

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformCatalogueGuard;
use App\Modules\Identity\Domain\Contracts\PlatformRoleRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;
use LogicException;

final readonly class PlatformCatalogueGuardService implements PlatformCatalogueGuard
{
    public function __construct(private AccountDirectory $accounts, private PlatformAccountDirectory $platform, private PlatformRoleRepository $roles,
        private ConnectionInterface $database) {}

    public function allows(int $userId): bool
    {
        return $this->accounts->verifiedAccountExists($userId) && $this->accounts->hasConfirmedMfa($userId) && $this->platform->isSuperadmin($userId);
    }

    public function lock(int $userId): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Platform catalogue guards require the write transaction.');
        }
        $this->accounts->lockAccountEmail($userId);
        if (! $this->roles->lockAdministration() || ! $this->allows($userId)) {
            throw new AuthorizationException;
        }
    }
}
