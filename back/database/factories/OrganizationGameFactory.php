<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrganizationGame> */
class OrganizationGameFactory extends Factory
{
    protected $model = OrganizationGame::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'game_id' => GameCatalogueEntry::factory(),
            'enabled' => true, 'restrictions' => [], 'version' => (string) Str::uuid()];
    }
}
