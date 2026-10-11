<?php

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Models\PlatformRole;

interface PlatformRoleRepository
{
    public function lockAdministration(): bool;

    public function hasAnyRole(): bool;

    public function hasBootstrap(): bool;

    public function activeSuperadmins(): int;

    public function findForAccount(int $userId): ?PlatformRole;

    /** @param array<string, mixed> $attributes */
    public function save(int $userId, array $attributes): PlatformRole;

    /** @param array<string, mixed> $attributes */
    public function audit(array $attributes): void;
}
