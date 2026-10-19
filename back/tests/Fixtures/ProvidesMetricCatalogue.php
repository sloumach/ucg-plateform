<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait ProvidesMetricCatalogue
{
    /** @return array<string,mixed> */
    private function metricScenario(bool $create = false): array
    {
        $this->travelTo('2026-10-19 10:00:00');
        $operator = User::factory()->withMfa()->create();
        app(PlatformRoleService::class)->configure($operator->id, PlatformRoleType::Superadmin, true, null, 'Initialisation de test', (string) Str::uuid(), true);
        $this->actingAsWithMfa($operator);
        $game = GameCatalogueEntry::factory()->create(['active' => true]);
        $mode = GameCatalogueEntry::factory()->create(['kind' => 'mode', 'game_id' => $game->id, 'scope_key' => $game->id, 'definition' => null]);
        $root = '/api/v1/metric-catalogue';
        $meta = $this->getJson($root)->assertOk()->json('meta');
        $input = ['game_id' => $game->id, 'code' => 'KD_RATIO', 'mode_id' => $mode->id, 'role_id' => null, 'release_id' => null, 'title' => 'K/D <script>global</script>', 'definition' => 'Ratio des éliminations aux morts dans ce contexte.', 'unit' => 'ratio', 'aggregation' => 'ratio', 'formula' => 'sum(kills) / sum(deaths)', 'zero_denominator' => 'unavailable', 'measurement_kind' => 'objective', 'availability' => 'source_required', 'availability_note' => 'Dépend des composantes fiables de la source, aucune collecte activée.', 'status' => 'active', 'reason' => 'Définition commune contrôlée avant usage.', 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true];
        $record = $create ? $this->postJson($root, $input)->assertCreated()->json('data') : null;

        return compact('operator', 'game', 'mode', 'root', 'input', 'record');
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function metricUpdate(array $s): array
    {
        $v = $s['input'];
        unset($v['game_id'],$v['code']);

        return [...$v, 'version' => $s['record']['version'], 'catalogue_version' => DB::table('metric_catalogue_state')->value('version')];
    }
}
