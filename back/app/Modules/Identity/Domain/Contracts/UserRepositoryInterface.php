<?php

namespace App\Modules\Identity\Domain\Contracts;

use App\Modules\Identity\Domain\Models\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
}
