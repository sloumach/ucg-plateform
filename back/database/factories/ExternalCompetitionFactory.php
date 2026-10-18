<?php

namespace Database\Factories;

use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExternalCompetition> */
class ExternalCompetitionFactory extends Factory
{
    protected $model = ExternalCompetition::class;

    /** @return array<string,mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'reference' => strtoupper(Str::random(12)), 'game_id' => GameCatalogueEntry::factory()->state(['default_enabled' => true]),
            'game_key' => fn (array $a) => GameCatalogueEntry::query()->whereKey($a['game_id'])->firstOrFail()->key, 'game_name' => fn (array $a) => GameCatalogueEntry::query()->whereKey($a['game_id'])->firstOrFail()->name,
            'name' => 'Circuit externe pilote', 'organizer' => 'Organisateur externe', 'region' => 'Europe', 'season' => '2026', 'website_url' => null, 'contacts' => [], 'status' => 'active', 'version' => (string) Str::uuid(),
            'created_by' => fn (array $a) => Organization::query()->whereKey($a['organization_id'])->firstOrFail()->owner_user_id];
    }
}
