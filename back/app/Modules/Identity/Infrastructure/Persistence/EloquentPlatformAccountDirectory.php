<?php

namespace App\Modules\Identity\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Domain\Models\PlatformRole;

final readonly class EloquentPlatformAccountDirectory implements PlatformAccountDirectory
{
    public function isTechnical(int $userId): bool
    {
        return PlatformRole::query()->where('user_id', $userId)->where('active', true)->exists();
    }

    public function isSuperadmin(int $userId): bool
    {
        return PlatformRole::query()->where('user_id', $userId)->where('active', true)->where('role', 'superadmin')->exists();
    }

    public function version(int $userId): ?string
    {
        return PlatformRole::query()->where('user_id', $userId)->where('active', true)->value('version');
    }
}
