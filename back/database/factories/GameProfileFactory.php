<?php

namespace Database\Factories;

use App\Modules\People\Domain\GameProfileStatus;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GameProfile> */
class GameProfileFactory extends Factory
{
    protected $model = GameProfile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['person_id' => Person::factory(),
            'organization_id' => fn (array $attributes) => Person::query()->whereKey($attributes['person_id'])->firstOrFail()->organization_id,
            'game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu',
            'rank' => null, 'game_role' => null, 'observed_at' => null,
            'status' => GameProfileStatus::Active, 'version' => (string) Str::uuid()];
    }
}
