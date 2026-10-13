<?php

namespace Database\Factories;

use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GameCatalogueEntry> */
class GameCatalogueEntryFactory extends Factory
{
    protected $model = GameCatalogueEntry::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['kind' => 'game', 'scope_key' => 'global', 'game_id' => null,
            'key' => 'game-'.Str::lower(Str::random(12)), 'name' => 'Jeu de test', 'active' => true, 'default_enabled' => false,
            'definition' => ['platforms' => ['pc' => ['steam']], 'regions' => ['eu']], 'version' => (string) Str::uuid()];
    }
}
