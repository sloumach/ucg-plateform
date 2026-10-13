<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TeamLineup> */
class TeamLineupFactory extends Factory
{
    protected $model = TeamLineup::class;

    public function definition(): array
    {
        return ['team_id' => Team::factory(), 'organization_id' => fn (array $a) => Team::query()->whereKey($a['team_id'])->firstOrFail()->organization_id,
            'game_id' => fn (array $a) => Team::query()->whereKey($a['team_id'])->firstOrFail()->game_id, 'purpose' => 'match',
            'label' => 'Scrim pilote', 'effective_at' => now()->startOfSecond(), 'status' => 'draft', 'version' => (string) Str::uuid(),
            'team_name' => fn (array $a) => Team::query()->whereKey($a['team_id'])->firstOrFail()->name,
            'game_key' => 'valorant', 'game_name' => 'VALORANT', 'season' => '2026',
            'created_by' => fn (array $a) => Organization::query()->whereKey($a['organization_id'])->firstOrFail()->owner_user_id,
            'finalized_by' => null, 'finalized_at' => null];
    }
}
