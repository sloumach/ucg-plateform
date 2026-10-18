<?php

namespace Tests\Fixtures;

use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

trait ProvidesMatchCorrections
{
    use ProvidesMatchResults;

    /** @return array<string,mixed> */
    private function correctionScenario(bool $approved = true): array
    {
        Bus::fake();
        config(['queue.default' => 'database']);
        $s = $this->resultScenario();
        if ($approved) {
            $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
            $this->actingAsWithMfa($s['reader']);
            $result = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertOk()->json('data');
            $s['record']['version'] = $result['match_version'];
            $s['submission'] = $result['submission'];
            $this->actingAsWithMfa($s['writer']);
        }
        $role = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->where('id', $role)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.correct', 'sports.validate']])->save();
        $s['game'] = $this->getJson($s['root'].'/games')->assertOk()->json('data.0');

        return $s;
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function correctionPayload(array $s): array
    {
        return ['version' => $s['record']['version'], 'score_revision' => null, 'idempotency_key' => (string) Str::uuid(), 'reason' => 'Le rapport source impose une correction motivée.',
            'confirm_correction' => true, 'games' => [['game_id' => $s['game']['id'], 'outcome' => 'played', 'results' => [
                ['participant_id' => $s['record']['participants'][0]['id'], 'score' => 0], ['participant_id' => $s['record']['participants'][1]['id'], 'score' => 13]]]]];
    }
}
