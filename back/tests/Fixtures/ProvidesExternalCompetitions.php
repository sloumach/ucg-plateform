<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Domain\Models\Organization;

trait ProvidesExternalCompetitions
{
    /**
     * @return array<string,mixed> */
    private function competitionScenario(bool $create = true): array
    {
        $this->travelTo('2026-10-18 10:00:00');
        $org = Organization::factory()->create();
        $writer = User::factory()->withMfa()->create();
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $writer, ['sports.read', 'sports.competitions.read', 'sports.competitions.write']);
        $this->grantPeopleAccess($org, $reader, ['sports.read', 'sports.competitions.read']);
        $game = GameCatalogueEntry::factory()->create(['default_enabled' => true]);
        $root = '/api/v1/tenants/'.$org->id.'/external-competitions';
        $this->actingAsWithMfa($writer);
        $body = $this->competitionPayload($game->id);
        $record = $create ? $this->postJson($root, $body)->assertCreated()->json('data') : null;

        return compact('org', 'writer', 'reader', 'game', 'root', 'record', 'body');
    }

    /**
     * @return array<string,mixed> */
    private function competitionPayload(string $game): array
    {
        return ['reference' => 'LEAGUE_2026', 'game_id' => $game, 'name' => '<script>Circuit pilote</script>', 'organizer' => 'Organisateur externe', 'region' => 'Europe', 'season' => '2026',
            'website_url' => 'https://league.example.test/rules', 'contacts' => [['name' => 'Contact privé', 'role' => 'Manager', 'email' => 'private@example.test', 'phone' => '+33 1 23 45 67 89']],
            'reason' => 'Participation externe consignée par le manager.'];
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function competitionDocument(array $s, string $kind = 'rules'): array
    {
        return ['competition_version' => $s['record']['version'], 'kind' => $kind, 'title' => 'Règlement privé <img>', 'body' => 'Règlement source : <script>texte conservé</script>',
            'source_url' => 'https://league.example.test/rules', 'reason' => 'Règlement reçu et conservé sans approbation automatique.'];
    }
}
