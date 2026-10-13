<?php

namespace App\Modules\Identity\Application\Contracts;

interface PlatformCatalogueGuard
{
    public function allows(int $userId): bool;

    /** Serializes authorization with platform-role administration inside the write transaction. */
    public function lock(int $userId): void;
}
