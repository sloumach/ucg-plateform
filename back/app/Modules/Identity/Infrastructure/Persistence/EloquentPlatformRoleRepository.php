<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Domain\Contracts\PlatformRoleRepository;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\PlatformRoleEvent;

final class EloquentPlatformRoleRepository implements PlatformRoleRepository
{
    public function lockAdministration(): bool
    {
        // The immutable bootstrap event also serializes later operator changes.
        return PlatformRoleEvent::query()->where('bootstrap_slot', 1)->lockForUpdate()->first() !== null;
    }

    public function hasAnyRole(): bool
    {
        return PlatformRole::query()->exists();
    }

    public function hasBootstrap(): bool
    {
        return PlatformRoleEvent::query()->where('bootstrap_slot', 1)->exists();
    }

    public function activeSuperadmins(): int
    {
        return PlatformRole::query()->where('role', 'superadmin')->where('active', true)->count();
    }

    public function findForAccount(int $userId): ?PlatformRole
    {
        return PlatformRole::query()->where('user_id', $userId)->first();
    }

    public function save(int $userId, array $attributes): PlatformRole
    {
        $role = $this->findForAccount($userId) ?? new PlatformRole;
        $role->forceFill(['user_id' => $userId, ...$attributes])->save();

        return $role;
    }

    public function audit(array $attributes): void
    {
        (new PlatformRoleEvent)->forceFill($attributes)->save();
    }
}
