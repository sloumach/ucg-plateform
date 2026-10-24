<?php

namespace Tests\Feature;

use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalCompetitionDocumentTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    public function test_versioned_rules_retain_original_content_and_private_text_download(): void
    {
        $s = $this->competitionScenario();
        $root = $s['root'].'/'.$s['record']['id'].'/documents';
        $doc = $this->postJson($root, $this->competitionDocument($s))->assertCreated()->json('data');
        $next = $this->patchJson($root.'/'.$doc['document_id'], ['competition_version' => $s['record']['version'], 'version' => $doc['version'], 'body' => 'Nouveau texte de règlement.', 'reason' => 'Le règlement révisé a été transmis par la source.'])->assertOk()->json('data');
        $this->getJson($root.'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.version', $next['version']);
        $this->getJson($root.'/'.$doc['document_id'].'/history?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.body', $this->competitionDocument($s)['body']);
        $this->actingAsWithMfa($s['reader']);
        $download = $this->get($root.'/'.$doc['document_id'].'/versions/'.$doc['version'].'/download')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $download->assertHeader('Content-Disposition', 'attachment; filename="competition-document-'.$doc['version'].'.txt"');
        $download->assertContent($this->competitionDocument($s)['title']."\n\n".$this->competitionDocument($s)['body']);
        $this->assertDatabaseCount('external_competition_documents', 2);
        $this->assertStringNotContainsString('texte conservé', DB::table('external_competition_documents')->value('body'));
    }

    public function test_stale_parent_and_document_versions_noops_and_duplicate_rules_are_refused(): void
    {
        $s = $this->competitionScenario();
        $root = $s['root'].'/'.$s['record']['id'].'/documents';
        $payload = $this->competitionDocument($s);
        $doc = $this->postJson($root, $payload)->assertCreated()->json('data');
        $this->postJson($root, $payload)->assertConflict()->assertJsonPath('code', 'EXTERNAL_COMPETITION_RULES_EXISTS');
        $update = ['competition_version' => $s['record']['version'], 'version' => $doc['version'], 'body' => 'Révision concurrente.', 'reason' => 'Le nouveau contenu a été vérifié par le manager.'];
        $this->patchJson($root.'/'.$doc['document_id'], [...$update, 'competition_version' => (string) Str::uuid()])->assertConflict();
        $this->patchJson($root.'/'.$doc['document_id'], [...$update, 'version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'EXTERNAL_COMPETITION_DOCUMENT_STALE');
        $this->patchJson($root.'/'.$doc['document_id'], [...$update, 'body' => $payload['body']])->assertUnprocessable();
        $this->patchJson($root.'/'.$doc['document_id'], [...$update, 'kind' => 'note'])->assertUnprocessable();
        $this->assertDatabaseCount('external_competition_documents', 1);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_documents_and_versions_do_not_cross_a_competition_boundary(): void
    {
        $s = $this->competitionScenario();
        $path = $s['root'].'/'.$s['record']['id'];
        $root = $path.'/documents';
        $doc = $this->postJson($root, $this->competitionDocument($s))->assertCreated()->json('data');
        $other = $this->postJson($s['root'], [...$s['body'], 'reference' => 'SECOND'])->assertCreated()->json('data');
        $foreignRoot = $s['root'].'/'.$other['id'].'/documents';
        $this->getJson($foreignRoot.'/'.$doc['document_id'].'/history')->assertNotFound();
        $this->getJson($foreignRoot.'/'.$doc['document_id'].'/versions/'.$doc['version'].'/download')->assertNotFound();
        $this->patchJson($foreignRoot.'/'.$doc['document_id'], ['competition_version' => $other['version'], 'version' => $doc['version'], 'body' => 'Tentative erronée.', 'reason' => 'Le document ne doit pas changer de dossier.'])->assertNotFound();
        $this->getJson($root.'/'.$doc['document_id'].'/versions/'.Str::uuid().'/download')->assertNotFound();
    }

    public function test_archive_freezes_documents_but_retains_their_history_and_downloads(): void
    {
        $s = $this->competitionScenario();
        $path = $s['root'].'/'.$s['record']['id'];
        $root = $path.'/documents';
        $doc = $this->postJson($root, $this->competitionDocument($s))->assertCreated()->json('data');
        $record = $this->patchJson($path, ['version' => $s['record']['version'], 'archive' => true, 'reason' => 'Fin de participation à la compétition externe.'])->assertOk()->json('data');
        $this->postJson($root, [...$this->competitionDocument($s), 'competition_version' => $record['version'], 'kind' => 'note'])->assertConflict();
        $this->patchJson($root.'/'.$doc['document_id'], ['competition_version' => $record['version'], 'version' => $doc['version'], 'body' => 'Changement interdit.', 'reason' => 'Le dossier archivé conserve les versions passées.'])->assertConflict();
        $this->getJson($root.'/'.$doc['document_id'].'/history')->assertOk();
        $this->get($root.'/'.$doc['document_id'].'/versions/'.$doc['version'].'/download')->assertOk();
    }

    #[Group('tenant-isolation')]
    public function test_reader_and_revoked_reader_cannot_modify_or_keep_downloading(): void
    {
        $s = $this->competitionScenario();
        $root = $s['root'].'/'.$s['record']['id'].'/documents';
        $doc = $this->postJson($root, $this->competitionDocument($s))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($root, $this->competitionDocument($s, 'note'))->assertForbidden();
        $role = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['reader']->id)->firstOrFail();
        OrganizationRole::query()->findOrFail($role->role_id)->forceFill(['permissions' => ['sports.read']])->save();
        $this->get($root.'/'.$doc['document_id'].'/versions/'.$doc['version'].'/download')->assertForbidden();
    }

    public function test_whitespace_oversized_body_private_url_and_unknown_upload_are_rejected(): void
    {
        $s = $this->competitionScenario();
        $root = $s['root'].'/'.$s['record']['id'].'/documents';
        $body = $this->competitionDocument($s);
        foreach ([['body' => '   '], ['body' => str_repeat('a', 20001)], ['source_url' => 'https://user:secret@example.test/a'], ['file' => 'untrusted-upload'], ['kind' => 'attachment']] as $invalid) {
            $this->postJson($root, [...$body, ...$invalid])->assertUnprocessable();
        }
        $this->assertDatabaseCount('external_competition_documents', 0);
    }
}
