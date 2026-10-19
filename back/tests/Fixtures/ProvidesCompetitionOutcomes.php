<?php

namespace Tests\Fixtures;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;

trait ProvidesCompetitionOutcomes
{
    /** @return array<string,mixed> */
    private function outcomeScenario(string $stream = 'sport', bool $create = false): array
    {
        $s = $this->competitionScenario();
        $this->outcomePermissions($s, ['sports.read', 'sports.competitions.read', 'sports.competitions.write', 'financial.read', 'financial.write']);
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id, 'created_by' => $s['writer']->id]);
        $s['match'] = $match;
        $s['path'] = $s['root'].'/'.$s['record']['id'].'/outcomes/'.$stream;
        $s['input'] = ['competition_version' => $s['record']['version'], 'expected_version' => null, 'reason' => 'Suivi interne vérifié auprès de l’organisateur.', 'source' => 'Rapport organisateur <script>privé</script>', 'source_url' => 'https://league.example.test/results', 'observed_on' => '2026-10-18', 'notes' => 'Information sourcée, sans publication.',
            ...($stream === 'sport' ? ['place' => 2, 'field_size' => 16, 'matches' => [['reference_id' => null, 'match_id' => $match->id, 'match_version' => $match->version]]] : ['currency' => 'EUR', 'announced_minor' => 100000, 'received_minor' => null, 'received_on' => null])];
        if ($create) {
            $s['revision'] = $this->patchJson($s['path'], $s['input'])->assertOk()->json('data');
        }

        return $s;
    }

    /** @param array<string,mixed> $s
     * @param list<string> $permissions */
    private function outcomePermissions(array $s, array $permissions): void
    {
        $role = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->whereKey($role)->firstOrFail()->forceFill(['permissions' => $permissions, 'privileged' => true])->save();
    }
}
