<?php

namespace App\Modules\Identity\Application\Contracts;

/** Platform roles are provisioned through trusted operator tooling, never tenant APIs. */
interface PlatformAccountDirectory
{
    public function isTechnical(int $userId): bool;

    public function isSuperadmin(int $userId): bool;

    public function version(int $userId): ?string;
}
