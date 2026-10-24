<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchResultService;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchResults;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchResultControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchResults, ProvidesPeopleAccess;

    public function test_submission_seals_server_snapshot_then_independent_reader_validator_approves(): void
    {
        $s = $this->resultScenario();
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->assertJsonPath('data.status', 'awaiting_validation')
            ->assertJsonPath('data.submission.snapshot.series.complete', true)->assertJsonPath('data.locked', true)->json('data');
        $this->assertNotSame($s['record']['version'], $result['match_version']);
        $this->assertStringNotContainsString('Référence', DB::table('match_result_events')->value('proof_reference'));
        $this->assertStringNotContainsString('participants', DB::table('match_result_events')->value('snapshot'));
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.result_locked', true);
        $this->postJson($s['root'].'/games', [...$this->scorePayload($s), 'version' => $result['match_version']])->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_LOCKED');
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['root'].'/results')->assertOk()->assertJsonPath('result.allowed_actions', ['approve', 'dispute']);
        $approved = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertOk()
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.current.action', 'approve')->json('data');
        $this->assertDatabaseCount('match_result_events', 2);
        $this->assertDatabaseCount('organization_audit_events', 7);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'validated', 'version' => $approved['match_version']]);
        $this->getJson($s['root'].'/results?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($s['root'].'/results?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.action', 'submit')->assertJsonMissingPath('data.0.contributors');
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertConflict()->assertJsonPath('code', 'MATCH_VERSION_STALE');
    }

    public function test_approver_cannot_validate_own_submission_or_scores_even_if_another_person_submits(): void
    {
        $s = $this->resultScenario();
        $roleId = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->value('role_id');
        OrganizationRole::query()->where('id', $roleId)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.validate']])->save();
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_INDEPENDENT_REQUIRED');
        $dispute = $this->decisionPayload($result);
        unset($dispute['confirm_evidence']);
        $disputed = $this->postJson($s['root'].'/results/disputes', $dispute)->assertOk()->json('data');
        $readerRole = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['reader']->id)->value('role_id');
        OrganizationRole::query()->where('id', $readerRole)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.validate']])->save();
        $this->actingAsWithMfa($s['reader']);
        $again = $this->postJson($s['root'].'/results', [...$this->resultPayload($s), 'version' => $disputed['match_version']])->assertCreated()->json('data');
        $this->actingAsWithMfa($s['writer']);
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($again))->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_INDEPENDENT_REQUIRED');
        $this->assertDatabaseCount('match_result_events', 3);
    }

    public function test_contestation_preserves_approved_result_and_requires_a_new_submission_before_approval(): void
    {
        $s = $this->resultScenario();
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $approved = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertOk()->json('data');
        $dispute = $this->decisionPayload($approved);
        unset($dispute['confirm_evidence']);
        $disputed = $this->postJson($s['root'].'/results/disputes', $dispute)->assertOk()->assertJsonPath('data.status', 'disputed')->json('data');
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($disputed))->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_TRANSITION_INVALID');
        $this->actingAsWithMfa($s['writer']);
        $new = $this->postJson($s['root'].'/results', [...$this->resultPayload($s), 'version' => $disputed['match_version'], 'proof_reference' => 'Nouveau rapport conservé R-2026-43'])->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/results/approvals', [...$this->decisionPayload($new), 'submission_id' => $result['submission']['id']])->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_SUBMISSION_STALE');
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($new))->assertOk()->assertJsonPath('data.status', 'validated');
        $this->assertDatabaseCount('match_result_events', 5);
        $this->assertDatabaseCount('match_game_instances', 1);
        $this->getJson($s['root'].'/results?per_page=100')->assertOk()->assertJsonPath('data.4.proof_reference', $this->resultPayload($s)['proof_reference']);
    }

    /** @return array<string,array{string,string}> */
    public static function exceptionalOutcomes(): array
    {
        return ['forfeit' => ['forfeit', 'forfeited'], 'abandon' => ['abandon', 'validated']];
    }

    #[DataProvider('exceptionalOutcomes')]
    public function test_awarded_outcome_does_not_invent_game_scores_and_can_be_contested(string $outcome, string $status): void
    {
        $s = $this->resultScenario(false);
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s, $outcome))->assertCreated()->assertJsonPath('data.submission.snapshot.series.complete', false)->json('data');
        $this->actingAsWithMfa($s['reader']);
        $approved = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertOk()->assertJsonPath('data.status', $status)->json('data');
        $body = $this->decisionPayload($approved);
        unset($body['confirm_evidence']);
        $this->postJson($s['root'].'/results/disputes', $body)->assertOk()->assertJsonPath('data.status', 'disputed');
        $this->assertDatabaseCount('match_game_instances', 0);
    }

    public function test_incomplete_score_manual_winner_unchecked_proof_and_unknown_fields_write_nothing(): void
    {
        $s = $this->resultScenario(false);
        $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_INCOMPLETE');
        $body = $this->resultPayload($s, 'forfeit');
        $this->postJson($s['root'].'/results', [...$body, 'snapshot' => []])->assertUnprocessable();
        $this->postJson($s['root'].'/results', [...$body, 'confirm_proof' => false])->assertUnprocessable()->assertJsonValidationErrors('confirm_proof');
        $this->postJson($s['root'].'/results', [...$body, 'proof_reference' => ' court '])->assertUnprocessable();
        $this->postJson($s['root'].'/results', [...$body, 'winner_participant_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('winner_participant_id');
        $this->assertDatabaseCount('match_result_events', 0);
    }

    #[Group('tenant-isolation')]
    public function test_audience_permissions_revocation_mfa_and_guests_cannot_bypass_the_workflow(): void
    {
        $s = $this->resultScenario();
        $this->postJson($s['root'].'/results/approvals', ['version' => $s['record']['version']])->assertForbidden();
        $unrelated = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $unrelated, ['sports.read', 'sports.validate']);
        $this->actingAsWithMfa($unrelated);
        $this->getJson($s['root'].'/results')->assertNotFound();
        $this->getJson($s['root'].'/results?management=1')->assertForbidden();
        $this->getJson($s['root'].'/results?per_page=101')->assertUnprocessable();
        $this->actingAsWithMfa($s['writer']);
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertForbidden();
        $this->actingAsWithMfa($s['reader']);
        OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['reader']->id)->update(['status' => 'suspended']);
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertForbidden();
        auth()->forgetGuards();
        $this->getJson($s['root'].'/results')->assertUnauthorized();
        $this->assertDatabaseCount('match_result_events', 1);
    }

    public function test_audit_failure_rolls_back_result_status_history_and_version(): void
    {
        $s = $this->resultScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertStatus(500);
        $this->assertDatabaseCount('match_result_events', 0);
        $this->assertDatabaseCount('match_status_events', 3);
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'in_progress', 'version' => $s['record']['version']]);
    }

    public function test_shared_result_mutations_require_publish_permission_and_new_confirmation(): void
    {
        [$org,$writer,$reader,$person,$other,$game] = $this->matchScenario();
        $roleId = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $writer->id)->value('role_id');
        $role = OrganizationRole::query()->where('id', $roleId)->firstOrFail();
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $record = $this->postJson($this->matchRoot($org), [...$this->matchPayload($game, $person, $other), 'visibility' => 'organization', 'confirm_sharing' => true])->assertCreated()->json('data');
        $root = $this->matchRoot($org).'/'.$record['id'];
        $record = $this->postJson($root.'/transitions', ['version' => $record['version'], 'status' => 'confirmed', 'reason' => 'Confirmation du match avant le forfait.'])->assertOk()->json('data');
        $body = $this->resultPayload(['record' => $record], 'forfeit');
        $this->postJson($root.'/results', $body)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $role->forceFill(['permissions' => ['sports.read', 'sports.write']])->save();
        $this->postJson($root.'/results', [...$body, 'confirm_sharing' => true])->assertForbidden();
        $role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.publish']])->save();
        $result = $this->postJson($root.'/results', [...$body, 'confirm_sharing' => true])->assertCreated()->json('data');
        $readerRole = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $reader->id)->value('role_id');
        OrganizationRole::query()->where('id', $readerRole)->firstOrFail()->forceFill(['permissions' => ['sports.read', 'sports.validate', 'sports.publish'], 'privileged' => true])->save();
        $this->actingAsWithMfa($reader);
        $this->postJson($root.'/results/approvals', $this->decisionPayload($result))->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');
        $this->postJson($root.'/results/approvals', [...$this->decisionPayload($result), 'confirm_sharing' => true])->assertOk()->assertJsonPath('data.status', 'forfeited');
        $this->assertDatabaseCount('match_game_instances', 0);
    }

    public function test_played_winner_override_missing_evidence_and_duplicate_submission_are_refused(): void
    {
        $s = $this->resultScenario();
        $body = $this->resultPayload($s);
        $this->postJson($s['root'].'/results', [...$body, 'winner_participant_id' => $s['record']['participants'][1]['id']])->assertUnprocessable()->assertJsonValidationErrors('winner_participant_id');
        $result = $this->postJson($s['root'].'/results', $body)->assertCreated()->json('data');
        $this->postJson($s['root'].'/results', [...$body, 'version' => $result['match_version']])->assertConflict()->assertJsonPath('code', 'MATCH_RESULT_TRANSITION_INVALID');
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/results/approvals', [...$this->decisionPayload($result), 'confirm_evidence' => false])->assertUnprocessable()->assertJsonValidationErrors('confirm_evidence');
        $this->assertDatabaseCount('match_result_events', 1);
    }

    public function test_service_validates_without_http_and_forbids_imposed_snapshot(): void
    {
        $s = $this->resultScenario();
        $this->expectException(ValidationException::class);
        app(MatchResultService::class)->change($this->personContext($s['org'], $s['writer']), $s['record']['id'], [...$this->resultPayload($s), 'contributors' => []], 'submit');
    }
}
