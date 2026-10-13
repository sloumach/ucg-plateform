<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TeamEligibilityRuleset> */
class TeamEligibilityRulesetFactory extends Factory
{
    protected $model = TeamEligibilityRuleset::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(), 'game_id' => fn () => GameCatalogueEntry::where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id,
            'name' => 'Coupe pilote', 'status' => 'active', 'version' => (string) Str::uuid(), 'min_starters' => 1, 'max_starters' => 5, 'max_substitutes' => 2,
            'platform_key' => null, 'allowed_regions' => [], 'allowed_ranks' => [], 'min_age' => null, 'max_age' => null, 'observation_max_days' => 30, 'lock_at' => null,
            'created_by' => fn (array $a) => Organization::query()->whereKey($a['organization_id'])->firstOrFail()->owner_user_id];
    }
}
