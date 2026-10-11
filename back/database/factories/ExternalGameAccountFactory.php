<?php

namespace Database\Factories;

use App\Modules\People\Domain\GameProfileStatus;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExternalGameAccount> */
class ExternalGameAccountFactory extends Factory
{
    protected $model = ExternalGameAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['game_profile_id' => GameProfile::factory(),
            'organization_id' => fn (array $attributes) => GameProfile::query()->whereKey($attributes['game_profile_id'])->firstOrFail()->organization_id,
            'game_key' => fn (array $attributes) => GameProfile::query()->whereKey($attributes['game_profile_id'])->firstOrFail()->game_key,
            'platform_key' => fn (array $attributes) => GameProfile::query()->whereKey($attributes['game_profile_id'])->firstOrFail()->platform_key,
            'region_key' => fn (array $attributes) => GameProfile::query()->whereKey($attributes['game_profile_id'])->firstOrFail()->region_key,
            'provider_key' => 'riot', 'external_id' => 'stable-'.(string) Str::uuid(),
            'display_name' => 'Camille#EU', 'observed_at' => null, 'status' => GameProfileStatus::Active, 'version' => (string) Str::uuid()];
    }
}
