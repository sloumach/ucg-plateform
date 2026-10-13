<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Team> */
class TeamFactory extends Factory
{
    protected $model = Team::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(),
            'game_id' => fn () => GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail()->id,
            'slug' => 'team-'.Str::lower(Str::random(12)), 'season' => '2026', 'name' => 'Équipe pilote',
            'kind' => 'first', 'custom_category' => null, 'short_name' => null, 'public_description' => null,
            'color' => null, 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
