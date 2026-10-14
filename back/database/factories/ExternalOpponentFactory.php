<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ExternalOpponent> */
class ExternalOpponentFactory extends Factory
{
    protected $model = ExternalOpponent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'key' => 'opponent-'.Str::lower(Str::random(12)),
            'kind' => 'team', 'name' => 'Adversaire public', 'short_name' => null, 'organization_name' => null,
            'game_id' => null, 'source_label' => 'Liste publique de compétition', 'source_url' => 'https://example.test/teams',
            'observed_at' => now()->subDay(), 'status' => 'active', 'version' => (string) Str::uuid()];
    }
}
