<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalCompetitionControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    public function test_create_read_update_and_archive_preserve_private_history(): void
    {
        $s = $this->competitionScenario();
        $path = $s['root'].'/'.$s['record']['id'];
        $this->getJson($s['root'].'?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', $s['body']['name']);
        $next = $this->patchJson($path, ['version' => $s['record']['version'], 'name' => 'Nouveau nom privé', 'reason' => 'Correction du nom selon la source officielle.'])->assertOk()->json('data');
        $this->assertDatabaseCount('external_competitions', 1);
        $this->assertDatabaseCount('external_competition_revisions', 2);
        $this->getJson($path.'/history?per_page=1')->assertOk()->assertJsonPath('data.0.snapshot.name', 'Nouveau nom privé')->assertJsonPath('meta.total', 2);
        $this->getJson($path.'/history?page=2&per_page=1')->assertOk()->assertJsonPath('data.0.snapshot.name', $s['body']['name']);
        $this->patchJson($path, ['version' => $next['version'], 'archive' => true, 'reason' => 'Saison terminée, dossier conservé en lecture seule.'])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($s['root'].'?status=archived')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertStringNotContainsString('private@example.test', DB::table('external_competitions')->value('contacts'));
        $this->assertSame([], array_intersect(['contacts', 'name', 'organizer'], array_keys(ExternalCompetition::query()->firstOrFail()->toArray())));
    }

    public function test_cross_tenant_record_and_history_are_not_disclosed(): void
    {
        $s = $this->competitionScenario();
        $foreign = Organization::factory()->create();
        $this->grantPeopleAccess($foreign, $s['writer'], ['sports.read', 'sports.competitions.read', 'sports.competitions.write']);
        $path = '/api/v1/tenants/'.$foreign->id.'/external-competitions/'.$s['record']['id'];
        foreach (['', '/history', '/documents'] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound();
        }
        $this->patchJson($path, ['version' => $s['record']['version'], 'name' => 'Autre nom', 'reason' => 'Modification depuis une autre organisation.'])->assertNotFound();
        $this->assertDatabaseCount('external_competition_revisions', 1);
    }

    public function test_reader_cannot_write_and_sports_read_alone_does_not_open_private_records(): void
    {
        $s = $this->competitionScenario();
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($s['root'], $s['body'])->assertForbidden();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $user, ['sports.read']);
        $this->actingAsWithMfa($user);
        $this->getJson($s['root'])->assertForbidden();
        $this->getJson($s['root'].'/'.$s['record']['id'].'/documents')->assertForbidden();
    }

    public function test_stale_noop_duplicate_and_archived_updates_have_no_partial_effects(): void
    {
        $s = $this->competitionScenario();
        $path = $s['root'].'/'.$s['record']['id'];
        $this->patchJson($path, ['version' => (string) Str::uuid(), 'name' => 'Autre nom', 'reason' => 'Modification avec une ancienne version.'])->assertConflict()->assertJsonPath('code', 'EXTERNAL_COMPETITION_VERSION_STALE');
        $this->postJson($s['root'], $s['body'])->assertConflict()->assertJsonPath('code', 'EXTERNAL_COMPETITION_REFERENCE_EXISTS');
        $this->patchJson($path, ['version' => $s['record']['version'], 'name' => $s['body']['name'], 'reason' => 'Nom inchangé après consultation du dossier.'])->assertUnprocessable()->assertJsonValidationErrors('record');
        $this->patchJson($path, ['version' => $s['record']['version'], 'archive' => true, 'name' => 'Autre nom', 'reason' => 'Archivage demandé avec un changement interdit.'])->assertUnprocessable();
        $archive = $this->patchJson($path, ['version' => $s['record']['version'], 'archive' => true, 'reason' => 'Archivage du dossier après fin de saison.'])->assertOk()->json('data');
        $this->patchJson($path, ['version' => $archive['version'], 'name' => 'Autre nom', 'reason' => 'Le dossier doit rester immuable après archivage.'])->assertConflict()->assertJsonPath('code', 'EXTERNAL_COMPETITION_ARCHIVED');
        $this->assertDatabaseCount('external_competition_revisions', 2);
    }

    public function test_disabled_game_is_not_selectable_but_existing_records_can_be_archived(): void
    {
        $s = $this->competitionScenario();
        OrganizationGame::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id, 'enabled' => false]);
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonMissing(['id' => $s['game']->id]);
        $this->postJson($s['root'], [...$s['body'], 'reference' => 'ANOTHER'])->assertConflict();
        $this->getJson($s['root'].'/'.$s['record']['id'])->assertOk();
        $this->patchJson($s['root'].'/'.$s['record']['id'], ['version' => $s['record']['version'], 'archive' => true, 'reason' => 'Jeu désactivé, conservation historique du dossier.'])->assertOk();
    }

    public function test_missing_fields_and_closed_payloads_return_localized_validation(): void
    {
        $s = $this->competitionScenario(false);
        $this->postJson($s['root'], [])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Ce champ est obligatoire.');
        $this->postJson($s['root'], [...$s['body'], 'organization_id' => $s['org']->id, 'visibility' => 'public'])->assertUnprocessable()->assertJsonValidationErrors(['organization_id', 'visibility']);
        $this->postJson($s['root'], [...$s['body'], 'contacts' => [['name' => 'Privé', 'role' => null, 'email' => null, 'phone' => null, 'permissions' => ['sports.publish']]]])->assertUnprocessable()->assertJsonValidationErrors(['contacts.0']);
        $this->postJson($s['root'], [...$s['body'], 'contacts.*.name' => 'Ignored key'])->assertUnprocessable();
        $this->assertDatabaseCount('external_competitions', 0);
    }

    /** @return array<string,array{string,mixed}> */
    public static function invalidValues(): array
    {
        return ['blank name' => ['name', '   '], 'blank organizer' => ['organizer', '  '], 'reference' => ['reference', 'lowercase'], 'bad email' => ['contacts', [['name' => 'Contact', 'role' => null, 'email' => 'invalid', 'phone' => null]]],
            'too many contacts' => ['contacts', array_fill(0, 11, ['name' => 'Contact', 'role' => null, 'email' => null, 'phone' => null])], 'private url' => ['website_url', 'https://127.0.0.1/private'],
            'credentials' => ['website_url', 'https://user:password@example.test/rules'], 'script url' => ['website_url', 'javascript:alert(1)'], 'reason whitespace' => ['reason', str_repeat(' ', 12)]];
    }

    #[DataProvider('invalidValues')]
    public function test_invalid_values_never_create_a_record(string $field, mixed $value): void
    {
        $s = $this->competitionScenario(false);
        $this->postJson($s['root'], [...$s['body'], $field => $value])->assertUnprocessable();
        $this->assertDatabaseCount('external_competitions', 0);
    }

    public function test_confirmation_revision_and_mfa_are_required_and_guest_is_denied(): void
    {
        $s = $this->competitionScenario(false);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id, 'revision' => $revision, 'confirmed' => false]])->withHeader('X-Tenant-Revision', $revision);
        $this->postJson($s['root'], $s['body'])->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->withHeader('X-Tenant-Revision', (string) Str::uuid())->postJson($s['root'], $s['body'])->assertConflict()->assertJsonPath('code', 'TENANT_CONTEXT_CHANGED');
        $this->withSession([MfaSession::PROOF_KEY => null])->getJson($s['root'])->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->assertDatabaseCount('external_competitions', 0);
    }

    public function test_guest_cannot_read_competitions(): void
    {
        $this->getJson('/api/v1/tenants/'.Str::uuid().'/external-competitions')->assertUnauthorized();
    }

    public function test_audit_failure_rolls_back_record_and_immutable_revision(): void
    {
        $s = $this->competitionScenario(false);
        $this->mock(TenantAuditJournal::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        });
        $this->postJson($s['root'], $s['body'])->assertStatus(500);
        $this->assertDatabaseCount('external_competitions', 0);
        $this->assertDatabaseCount('external_competition_revisions', 0);
    }

    public function test_audit_keeps_identifiers_without_contacts_or_document_contents(): void
    {
        $s = $this->competitionScenario();
        $audit = DB::table('organization_audit_events')->where('organization_id', $s['org']->id)->first();
        $encoded = json_encode($audit, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private@example.test', $encoded);
        $this->assertStringNotContainsString('Circuit pilote', $encoded);
        $this->getJson($s['root'].'?per_page=26')->assertUnprocessable();
        $this->getJson($s['root'].'?status=active&region=Europe&season=2026&game_id='.$s['game']->id)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_contact_key_order_is_not_a_meaningful_change_and_contact_lists_are_strict(): void
    {
        $s = $this->competitionScenario();
        $contact = $s['body']['contacts'][0];
        $this->patchJson($s['root'].'/'.$s['record']['id'], ['version' => $s['record']['version'], 'contacts' => [array_reverse($contact, true)], 'reason' => 'Les mêmes coordonnées ont été envoyées dans un autre ordre.'])->assertUnprocessable()->assertJsonValidationErrors('record');
        $this->postJson($s['root'], [...$s['body'], 'reference' => 'OTHER_LIST', 'contacts' => ['custom-key' => $contact]])->assertUnprocessable()->assertJsonValidationErrors('contacts');
        $this->assertDatabaseCount('external_competition_revisions', 1);
    }
}
