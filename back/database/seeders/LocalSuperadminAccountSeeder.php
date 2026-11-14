<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalSuperadminAccountSeeder extends Seeder
{
    private const string EMAIL = 'superadmin@ucg.local';

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        if (User::query()->where('email', self::EMAIL)->exists()) {
            return;
        }

        $password = $this->command->secret('Mot de passe du compte super admin local (16 à 72 octets)');
        if (! is_string($password) || strlen($password) < 16 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new RuntimeException('Un mot de passe de 16 à 72 octets est requis.');
        }

        User::query()->firstOrCreate(['email' => self::EMAIL], [
            'name' => 'Super admin local',
            'email_verified_at' => now(),
            'password' => $password,
        ]);
    }
}
