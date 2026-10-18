<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchNotebookService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchResults;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchNotebookControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchResults, ProvidesPeopleAccess;

    /** @return array<string,mixed> */
    private function scenario(): array
    {

        [$org, $writer, $reader, $person, $other, $game] = $this->matchScenario();

        $record = $this->postJson($this->matchRoot($org), $this->matchPayload($game, $person, $other))->assertCreated()->json('data');

        $this->permissions($org->id, $writer->id, ['sports.read', 'sports.write', 'sports.notes.read', 'sports.notes.write', 'sports.strategies.read', 'sports.strategies.write', 'sports.publish']);

        return compact('org', 'writer', 'reader', 'record') + ['root' => $this->matchRoot($org).'/'.$record['id'].'/notebook'];

    }

    /** @param list<string> $permissions */
    private function permissions(string $org, int $user, array $permissions): void
    {

        $id = OrganizationRoleAssignment::query()->where('organization_id', $org)->where('user_id', $user)->value('role_id');

        OrganizationRole::query()->where('id', $id)->firstOrFail()->forceFill(['permissions' => $permissions, 'privileged' => true])->save();

    }

    /** @return array<string,mixed> */
    private function payload(string $audience = 'internal', string $kind = 'note'): array
    {

        return ['audience' => $audience, 'kind' => $kind, 'title' => 'Préparation du match', 'body' => '<script>Objectif tactique confidentiel</script>',

            ...($kind === 'vod' ? ['vod_url' => 'https://example.com/vod/42', 'timestamp_seconds' => 123] : []),

            ...($kind === 'action' ? ['state' => 'open', 'due_at' => '2026-10-22T12:00:00+00:00'] : []),

            ...($audience === 'shared' ? ['confirm_sharing' => true] : [])];

    }

    public function test_internal_strategy_and_shared_contents_are_filtered_before_pagination_and_history(): void
    {

        $s = $this->scenario();

        $internal = $this->postJson($s['root'], $this->payload())->assertCreated()->json('data');

        $strategy = $this->postJson($s['root'], $this->payload('strategy'))->assertCreated()->json('data');

        $this->postJson($s['root'], $this->payload('shared'))->assertCreated();

        $this->getJson($s['root'].'?per_page=1')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonCount(1, 'data')->assertHeader('Cache-Control', 'no-store, private');

        $this->actingAsWithMfa($s['reader']);

        $this->getJson($s['root'].'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.audience', 'shared');

        foreach ([$internal, $strategy] as $entry) {

            $this->getJson($s['root'].'/'.$entry['entry_id'].'/history')->assertNotFound();

            $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], 'title' => 'Contenu modifié'])->assertForbidden();

        }

        $this->permissions($s['org']->id, $s['reader']->id, ['sports.read', 'sports.notes.read']);

        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson($s['root'].'/'.$strategy['entry_id'].'/history')->assertNotFound();

        $this->getJson($s['root'].'?audience=strategy')->assertForbidden();

    }

    /** @return array<string,array{string}> */
    public static function kinds(): array
    {
        return array_combine(['objective', 'note', 'vod', 'debrief', 'action'], array_map(fn ($k) => [$k], ['objective', 'note', 'vod', 'debrief', 'action']));
    }

    #[DataProvider('kinds')]
    public function test_creates_and_revises_every_content_type_without_changing_match_score_version(string $kind): void
    {

        $s = $this->scenario();

        $entry = $this->postJson($s['root'], $this->payload('internal', $kind))->assertCreated()->assertJsonPath('data.kind', $kind)->json('data');

        $revised = $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], 'body' => 'Débrief revu après analyse.', ...($kind === 'action' ? ['state' => 'done'] : [])])->assertOk()->assertJsonPath('data.number', 2)->json('data');

        $this->assertNotSame($entry['version'], $revised['version']);

        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.body', 'Débrief revu après analyse.');

        $this->getJson($s['root'].'/'.$entry['entry_id'].'/history?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.body', '<script>Objectif tactique confidentiel</script>');

        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'version' => $s['record']['version']]);

        $this->assertStringNotContainsString('confidentiel', DB::table('match_notebook_revisions')->value('body'));

        $this->assertStringNotContainsString('Préparation', DB::table('match_notebook_revisions')->value('title'));

        $this->assertDatabaseCount('match_notebook_revisions', 2);

        $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], 'title' => 'Modification obsolète'])->assertConflict()->assertJsonPath('code', 'MATCH_NOTEBOOK_VERSION_STALE');

    }

    public function test_sharing_requires_publication_permission_and_confirmation_even_when_editing(): void
    {

        $s = $this->scenario();

        $this->permissions($s['org']->id, $s['writer']->id, ['sports.read', 'sports.write', 'sports.notes.read', 'sports.notes.write']);

        $this->postJson($s['root'], $this->payload('shared'))->assertForbidden();

        $this->permissions($s['org']->id, $s['writer']->id, ['sports.read', 'sports.write', 'sports.publish']);

        $data = $this->payload('shared');
        unset($data['confirm_sharing']);

        $this->postJson($s['root'], $data)->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');

        $entry = $this->postJson($s['root'], $this->payload('shared'))->assertCreated()->json('data');

        $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], 'body' => 'Texte public à revoir.'])->assertUnprocessable()->assertJsonValidationErrors('confirm_sharing');

        $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], 'body' => 'Texte partageable vérifié.', 'confirm_sharing' => true])->assertOk();

        $this->postJson($s['root'], $this->payload('internal'))->assertForbidden();

    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalid(): array
    {

        return ['unknown' => [['organization_id' => 'forged']], 'kind' => [['kind' => 'score']], 'audience' => [['audience' => 'public']],

            'blank title' => [['title' => '   ']], 'blank body' => [['body' => '   ']], 'long title' => [['title' => str_repeat('x', 201)]],

            'long body' => [['body' => str_repeat('x', 10001)]], 'unsafe url' => [['kind' => 'vod', 'vod_url' => 'javascript:alert(1)']],

            'http' => [['kind' => 'vod', 'vod_url' => 'http://example.com']], 'credentials' => [['kind' => 'vod', 'vod_url' => 'https://user:pass@example.com']],

            'missing vod' => [['kind' => 'vod']], 'wrong timestamp' => [['kind' => 'vod', 'vod_url' => 'https://example.com', 'timestamp_seconds' => -1]],

            'non vod url' => [['vod_url' => 'https://example.com']], 'non action state' => [['state' => 'done']],

            'invalid action' => [['kind' => 'action', 'state' => 'closed']], 'non action date' => [['due_at' => '2026-10-22T12:00:00+00:00']]];

    }

    /** @param array<string,mixed> $override */
    #[DataProvider('invalid')]
    public function test_rejects_invalid_and_unexpected_input_without_writes(array $override): void
    {

        $s = $this->scenario();

        $this->postJson($s['root'], [...$this->payload(), ...$override])->assertUnprocessable();

        $this->assertDatabaseCount('match_notebook_revisions', 0);

    }

    public function test_cannot_change_audience_kind_or_tenant_through_a_revision_or_bypass_service_validation(): void
    {

        $s = $this->scenario();

        $entry = $this->postJson($s['root'], $this->payload('strategy'))->assertCreated()->json('data');

        foreach (['audience' => 'shared', 'kind' => 'vod', 'organization_id' => (string) Str::uuid()] as $key => $value) {

            $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], $key => $value])->assertUnprocessable();

        }

        $this->expectException(ValidationException::class);

        app(MatchNotebookService::class)->change($this->personContext($s['org'], $s['writer']), $s['record']['id'], null, [...$this->payload(), 'actor_user_id' => 1], null);

    }

    public function test_other_tenant_match_and_foreign_entry_are_not_disclosed(): void
    {

        $s = $this->scenario();

        $entry = $this->postJson($s['root'], $this->payload())->assertCreated()->json('data');

        $foreign = MatchRecord::factory()->create();

        $base = $this->matchRoot($s['org']).'/'.$foreign->id.'/notebook';

        $this->getJson($base)->assertNotFound();

        $this->postJson($base, $this->payload())->assertNotFound();

        $this->getJson($base.'/'.$entry['entry_id'].'/history')->assertNotFound();

        $this->patchJson($base.'/'.$entry['entry_id'], ['version' => $entry['version'], 'body' => 'Tentative étrangère.'])->assertNotFound();

        $this->getJson($s['root'].'/'.Str::uuid().'/history')->assertNotFound();

    }

    public function test_management_does_not_bypass_strategy_rights_and_rechecks_revocation(): void
    {

        $s = $this->scenario();

        $entry = $this->postJson($s['root'], $this->payload('strategy'))->assertCreated()->json('data');

        $this->permissions($s['org']->id, $s['writer']->id, ['sports.read', 'sports.write']);

        $this->getJson($s['root'].'?management=1')->assertOk()->assertJsonPath('meta.total', 0);

        $this->getJson($s['root'].'/'.$entry['entry_id'].'/history?management=1')->assertNotFound();

        $this->patchJson($s['root'].'/'.$entry['entry_id'], ['version' => $entry['version'], 'body' => 'Lecture révoquée.'])->assertNotFound();

        $this->assertDatabaseCount('match_notebook_revisions', 1);

    }

    public function test_authentication_mfa_and_confirmed_tenant_are_required_for_changes(): void
    {

        $s = $this->scenario();

        $this->app['auth']->forgetGuards();

        $this->getJson($s['root'])->assertUnauthorized();

        $this->postJson($s['root'], $this->payload())->assertUnauthorized();

        $this->actingAsWithMfa($s['writer']);

        $this->session([MfaSession::PROOF_KEY => null]);

        $this->postJson($s['root'], $this->payload())->assertForbidden();

    }

    public function test_debrief_remains_editable_after_approval_without_changing_any_result_or_score(): void
    {
        $s = $this->resultScenario();
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $approved = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertOk()->json('data');
        $this->actingAsWithMfa($s['writer']);
        $this->permissions($s['org']->id, $s['writer']->id, ['sports.read', 'sports.write', 'sports.notes.read', 'sports.notes.write']);
        $entry = $this->postJson($s['root'].'/notebook', $this->payload('internal', 'debrief'))->assertCreated()->json('data');
        $this->patchJson($s['root'].'/notebook/'.$entry['entry_id'], ['version' => $entry['version'], 'body' => 'Débrief final après analyse du résultat validé.'])->assertOk();
        $this->assertDatabaseHas('matches', ['id' => $s['record']['id'], 'status' => 'validated', 'version' => $approved['match_version']]);
        $this->assertDatabaseCount('match_result_events', 2);
        $this->assertDatabaseCount('match_game_instances', 1);
    }

    public function test_unconfirmed_selection_and_old_session_revision_cannot_mutate_notes(): void
    {
        $s = $this->scenario();
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => [
            'organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id, 'revision' => $revision, 'confirmed' => false,
        ]])->withHeader('X-Tenant-Revision', $revision);
        $this->postJson($s['root'], $this->payload())->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->withHeader('X-Tenant-Revision', (string) Str::uuid())->postJson($s['root'], $this->payload())->assertConflict()->assertJsonPath('code', 'TENANT_CONTEXT_CHANGED');
        $this->assertDatabaseCount('match_notebook_revisions', 0);
    }

    public function test_audit_failure_rolls_back_the_revision(): void
    {

        $s = $this->scenario();

        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));

        $this->postJson($s['root'], $this->payload())->assertStatus(500);

        $this->assertDatabaseCount('match_notebook_revisions', 0);

    }
}
