<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Domain\Models\Organization;

trait ProvidesObservations
{
    /** @return array<string,mixed> */
    private function observationScenario(bool $create = false): array
    {
        $s = $this->metricScenario();
        $meta = $this->getJson($s['root'])->assertOk()->json('meta');
        $metric = $this->postJson($s['root'], [...$s['input'], 'mode_id' => null, 'aggregation_level' => 'period', 'aggregation' => 'manual', 'zero_denominator' => 'not_applicable', 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version']])->assertCreated()->json('data');
        $org = Organization::factory()->create();
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $s['game']->id, 'enabled' => true]);
        $writer = User::factory()->withMfa()->create();
        $reader = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($org, $writer, ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.correct']);
        $this->grantPeopleAccess($org, $reader, ['sports.read', 'sports.statistics.read']);
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $this->actingAsWithMfa($writer);
        $root = '/api/v1/tenants/'.$org->id.'/observations';
        $input = ['metric_id' => $metric['id'], 'metric_version' => $metric['version'], 'person_id' => $person->id, 'mode_id' => null, 'role_id' => null, 'release_id' => null, 'match_id' => null, 'match_game_id' => null, 'source_kind' => 'training', 'source_label' => 'Grille privée <script>coach</script>', 'period_start' => '2026-10-01', 'period_end' => '2026-10-18', 'observed_on' => '2026-10-19', 'sample_size' => 3, 'region' => 'Europe', 'season' => '2026', 'map' => null, 'opponent' => null, 'round_number' => null, 'session_label' => null, 'availability' => 'available', 'value' => '0.000', 'missing_reason' => null, 'notes' => 'Note privée <img>', 'status' => 'active', 'reason' => 'Observation vérifiée sur la source manuelle.'];
        $record = $create ? $this->postJson($root, $input)->assertCreated()->json('data') : null;

        return [...$s, ...compact('org', 'writer', 'reader', 'person', 'assignment', 'metric', 'root', 'input', 'record')];
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function observationCorrection(array $s): array
    {
        return ['version' => $s['record']['version'], 'availability' => 'available', 'value' => '12.125000', 'missing_reason' => null, 'notes' => 'Correction contrôlée <script>texte</script>', 'status' => 'active', 'reason' => 'Correction motivée après vérification de la source.'];
    }
}
