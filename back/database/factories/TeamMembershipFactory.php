<?php

namespace Database\Factories;

use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TeamMembership> */
class TeamMembershipFactory extends Factory
{
    protected $model = TeamMembership::class;

    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'organization_id' => fn (array $attributes) => Team::query()->whereKey($attributes['team_id'])->firstOrFail()->organization_id,
            'person_id' => fn (array $attributes) => Person::factory()->create(['organization_id' => $attributes['organization_id'], 'status' => 'active'])->id,
            'role_key' => 'player', 'starts_at' => now()->subDay(), 'ends_at' => null, 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
