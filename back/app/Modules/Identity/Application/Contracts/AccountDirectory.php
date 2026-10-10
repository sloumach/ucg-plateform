<?php

namespace App\Modules\Identity\Application\Contracts;

interface AccountDirectory
{
    public function verifiedAccountExists(int $userId): bool;
}
