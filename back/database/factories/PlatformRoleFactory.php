<?php

namespace Database\Factories;

use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PlatformRole> */
final class PlatformRoleFactory extends Factory
{
    protected $model = PlatformRole::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['user_id' => User::factory()->withMfa(), 'role' => 'support', 'active' => true, 'version' => (string) Str::uuid()];
    }

    public function superadmin(): static
    {
        return $this->state(fn (): array => ['role' => 'superadmin']);
    }
}
