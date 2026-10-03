<?php

namespace App\Modules\Identity\Presentation\Policies;

use App\Modules\Identity\Domain\Models\User;

class UserPolicy
{
    public function view(User $user, User $model): bool
    {
        return $user->is($model);
    }
}
