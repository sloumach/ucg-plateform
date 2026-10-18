<?php

namespace Tests\Fixtures;

use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;

trait ProvidesMatchResults
{
    use ProvidesMatchScores;

    /** @return array<string,mixed> */
    private function resultScenario(bool $scored = true): array
    {
        $s = $this->scoreScenario('bo1');
        if ($scored) {
            $s['record']['version'] = $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated()->json('data.match_version');
        }
        $role = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['reader']->id)->value('role_id');
        OrganizationRole::query()->where('id', $role)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.validate'], 'privileged' => true])->save();

        return $s;
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function resultPayload(array $s, string $outcome = 'played'): array
    {
        return ['version' => $s['record']['version'], 'outcome' => $outcome, 'proof_reference' => '<script>Référence au rapport conservé R-2026-42</script>',
            'confirm_proof' => true, 'reason' => 'Résultat proposé selon le rapport conservé.',
            ...($outcome === 'played' ? [] : ['winner_participant_id' => $s['record']['participants'][0]['id']])];
    }

    /** @param array<string,mixed> $result
     * @return array<string,mixed> */
    private function decisionPayload(array $result): array
    {
        return ['version' => $result['match_version'], 'submission_id' => $result['submission']['id'], 'confirm_evidence' => true, 'reason' => 'Preuve consultée et résultat vérifié personnellement.'];
    }
}
