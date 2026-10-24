<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalOpponentControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guests_receive_401_for_reads_and_writes(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->path($org))->assertUnauthorized();
        $this->postJson($this->path($org), $this->payload())->assertUnauthorized();
    }

    public function test_creation_is_private_audited_and_does_not_grant_access_or_fetch_the_source(): void
    {
        $org = $this->editor();
        Http::preventStrayRequests();
        Http::fake();
        $members = DB::table('organization_memberships')->count();
        $roles = DB::table('organization_role_assignments')->count();
        $users = DB::table('users')->count();
        $response = $this->postJson($this->path($org), [...$this->payload(), 'source_label' => 'PUBLIC_LABEL'])
            ->assertCreated()->assertJsonPath('data.publication_status', 'private')
            ->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.user_id')
            ->assertJsonPath('notification.message', 'La référence externe a été enregistrée.');
        $this->assertDatabaseHas('external_opponents', ['id' => $response->json('data.id'), 'organization_id' => $org->id, 'name' => 'Équipe adverse']);
        $this->assertDatabaseCount('organization_audit_events', 1);
        $this->assertDatabaseCount('organization_memberships', $members);
        $this->assertDatabaseCount('organization_role_assignments', $roles);
        $this->assertDatabaseCount('users', $users);
        $event = DB::table('organization_audit_events')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('PUBLIC_LABEL', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertSame($response->json('data.id'), json_decode($event->after, true)['external_opponent_id']);
        Http::assertNothingSent();
    }

    #[Group('tenant-isolation')]
    public function test_foreign_ids_return_404_for_read_update_capture_and_history_even_for_a_member_of_both_tenants(): void
    {
        $org = $this->editor();
        $foreign = ExternalOpponent::factory()->create();
        $this->grantPeopleAccess(Organization::findOrFail($foreign->organization_id), User::findOrFail(auth()->id()), ['sports.read', 'teams.manage']);
        $this->getJson($this->path($org, $foreign->id))->assertNotFound();
        $this->patchJson($this->path($org, $foreign->id), ['version' => $foreign->version, 'name' => 'Hijack', 'confirm_public_data' => true])->assertNotFound();
        $this->postJson($this->path($org, $foreign->id).'/snapshots', ['version' => $foreign->version, 'context_label' => 'Match', 'confirm_public_data' => true])->assertNotFound();
        $this->getJson($this->path($org, $foreign->id).'/snapshots')->assertNotFound();
        $this->assertDatabaseHas('external_opponents', ['id' => $foreign->id, 'name' => 'Adversaire public']);
        $this->assertDatabaseCount('external_opponent_snapshots', 0);
    }

    public function test_reader_can_search_but_cannot_create_update_or_capture(): void
    {
        $org = $this->editor(['sports.read']);
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $this->getJson($this->path($org))->assertOk()->assertJsonCount(1, 'data');
        $this->postJson($this->path($org), $this->payload())->assertForbidden();
        $this->patchJson($this->path($org, $opponent->id), ['version' => $opponent->version, 'name' => 'Changed', 'confirm_public_data' => true])->assertForbidden();
        $this->postJson($this->path($org, $opponent->id).'/snapshots', ['version' => $opponent->version, 'context_label' => 'Match', 'confirm_public_data' => true])->assertForbidden();
        $this->assertDatabaseCount('external_opponents', 1);
        $this->assertDatabaseCount('external_opponent_snapshots', 0);
    }

    public function test_list_is_paginated_filtered_and_treats_search_wildcards_as_literals(): void
    {
        $org = $this->editor(['sports.read']);
        ExternalOpponent::factory()->create(['organization_id' => $org->id, 'name' => 'Alpha%', 'kind' => 'organization']);
        ExternalOpponent::factory()->create(['organization_id' => $org->id, 'name' => 'AlphaBeta', 'status' => 'archived']);
        ExternalOpponent::factory()->create(['name' => 'FOREIGN_SECRET']);
        $this->getJson($this->path($org).'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->path($org).'?q=Alpha%25')->assertOk()->assertJsonPath('data.0.name', 'Alpha%')->assertJsonCount(1, 'data');
        $this->getJson($this->path($org).'?kind=organization&status=active')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path($org).'?q=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($org).'?sort=name%3Bdelete')->assertUnprocessable();
        $this->getJson($this->path($org).'?per_page=101')->assertUnprocessable();
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidInputs(): array
    {
        return [
            'tenant injection' => [['organization_id' => 'spoof'], 'organization_id'],
            'internal organization' => [['linked_tenant_id' => 'spoof'], 'linked_tenant_id'],
            'private roster' => [['roster' => ['SECRET']], 'roster'],
            'account binding' => [['user_id' => 42], 'user_id'],
            'permission grant' => [['permissions' => ['teams.manage']], 'permissions'],
            'automatic publication' => [['published' => true], 'published'],
            'name required' => [['name' => null], 'name'],
            'name length' => [['name' => str_repeat('a', 121)], 'name'],
            'name empty' => [['name' => ' '], 'name'],
            'kind' => [['kind' => 'tenant'], 'kind'],
            'key' => [['key' => 'Bad Key'], 'key'],
            'short name' => [['short_name' => str_repeat('a', 25)], 'short_name'],
            'organization label' => [['organization_name' => str_repeat('a', 121)], 'organization_name'],
            'source length' => [['source_label' => str_repeat('a', 161)], 'source_label'],
            'source required' => [['source_label' => null], 'source_label'],
            'future observation' => [['observed_at' => '2099-01-01T00:00:00+00:00'], 'observed_at'],
            'timezone required' => [['observed_at' => '2026-01-01'], 'observed_at'],
            'observation required' => [['observed_at' => null], 'observed_at'],
            'game uuid' => [['game_id' => 'bad'], 'game_id'],
            'status' => [['status' => 'published'], 'status'],
            'public declaration' => [['confirm_public_data' => false], 'confirm_public_data'],
            'javascript url' => [['source_url' => 'javascript:alert(1)'], 'source_url'],
            'plain http' => [['source_url' => 'http://example.test'], 'source_url'],
            'credentials' => [['source_url' => 'https://user:secret@example.test'], 'source_url'],
            'private ip' => [['source_url' => 'https://127.0.0.1/path'], 'source_url'],
            'localhost' => [['source_url' => 'https://localhost/path'], 'source_url'],
            'local domain' => [['source_url' => 'https://intranet.local/path'], 'source_url'],
            'url length' => [['source_url' => 'https://example.test/'.str_repeat('a', 1000)], 'source_url'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidInputs')]
    public function test_invalid_or_confidential_payload_returns_422_without_writes(array $change, string $field): void
    {
        $org = $this->editor();
        $this->postJson($this->path($org), [...$this->payload(), ...$change])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('external_opponents', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_partial_update_preserves_source_date_and_archival_reserves_key(): void
    {
        $org = $this->editor();
        $created = $this->postJson($this->path($org), $this->payload())->assertCreated();
        $updated = $this->patchJson($this->path($org, $created->json('data.id')), ['version' => $created->json('data.version'), 'name' => 'Renommée', 'confirm_public_data' => true])
            ->assertOk()->assertJsonPath('data.observed_at', '2026-01-01T00:00:00+00:00')->assertJsonPath('data.source_label', 'Liste publique');
        $archived = $this->patchJson($this->path($org, $created->json('data.id')), ['version' => $updated->json('data.version'), 'status' => 'archived', 'confirm_public_data' => true])->assertOk();
        $this->patchJson($this->path($org, $created->json('data.id')), ['version' => $archived->json('data.version'), 'name' => 'Overwrite', 'confirm_public_data' => true])->assertConflict()->assertJsonPath('code', 'EXTERNAL_OPPONENT_ARCHIVED');
        $this->postJson($this->path($org), $this->payload())->assertConflict()->assertJsonPath('code', 'EXTERNAL_OPPONENT_KEY_ALREADY_EXISTS');
        ExternalOpponent::factory()->create(['key' => $this->payload()['key']]);
        $this->assertDatabaseCount('external_opponents', 2);
        $this->deleteJson($this->path($org, $created->json('data.id')))->assertStatus(405);
    }

    public function test_stale_versions_and_identity_changes_cannot_overwrite_a_reference(): void
    {
        $org = $this->editor();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $this->patchJson($this->path($org, $opponent->id), ['version' => (string) Str::uuid(), 'name' => 'Stale', 'confirm_public_data' => true])->assertConflict()->assertJsonPath('code', 'EXTERNAL_OPPONENT_VERSION_STALE');
        $this->patchJson($this->path($org, $opponent->id), ['version' => $opponent->version, 'kind' => 'organization', 'game_id' => null, 'key' => 'other', 'confirm_public_data' => true])->assertUnprocessable()->assertJsonValidationErrors(['kind', 'game_id', 'key']);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_snapshot_copies_current_public_facts_and_survives_rename_and_archive(): void
    {
        $org = $this->editor();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id, 'name' => 'Ancien nom']);
        $response = $this->postJson($this->path($org, $opponent->id).'/snapshots', ['version' => $opponent->version, 'context_label' => 'Compétition externe', 'confirm_public_data' => true])->assertCreated()
            ->assertJsonPath('data.name', 'Ancien nom')->assertJsonPath('data.opponent_version', $opponent->version)
            ->assertJsonPath('data.publication_status', 'private')->assertJsonMissingPath('data.organization_id');
        $this->assertDatabaseHas('external_opponent_snapshots', ['id' => $response->json('data.id'), 'organization_id' => $org->id, 'name' => 'Ancien nom']);
        $updated = $this->patchJson($this->path($org, $opponent->id), ['version' => $opponent->version, 'name' => 'Nouveau nom', 'confirm_public_data' => true])->assertOk();
        $this->patchJson($this->path($org, $opponent->id), ['version' => $updated->json('data.version'), 'status' => 'archived', 'confirm_public_data' => true])->assertOk();
        $this->getJson($this->path($org, $opponent->id).'/snapshots?per_page=1')->assertOk()->assertJsonPath('data.0.name', 'Ancien nom');
        $this->assertDatabaseCount('organization_audit_events', 3);
    }

    public function test_snapshot_rejects_stale_archived_and_client_supplied_facts(): void
    {
        $org = $this->editor();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $body = ['version' => $opponent->version, 'context_label' => 'Match', 'confirm_public_data' => true];
        $this->postJson($this->path($org, $opponent->id).'/snapshots', [...$body, 'name' => 'Spoof', 'competition_id' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors(['name', 'competition_id']);
        $this->postJson($this->path($org, $opponent->id).'/snapshots', [...$body, 'version' => (string) Str::uuid()])->assertConflict();
        $opponent->forceFill(['status' => 'archived'])->save();
        $this->postJson($this->path($org, $opponent->id).'/snapshots', $body)->assertConflict()->assertJsonPath('code', 'EXTERNAL_OPPONENT_ARCHIVED');
        $this->assertDatabaseCount('external_opponent_snapshots', 0);
    }

    public function test_unavailable_game_blocks_new_use_but_allows_read_and_archive(): void
    {
        $org = $this->editor();
        $game = GameCatalogueEntry::query()->where('kind', 'game')->where('key', 'valorant')->firstOrFail();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id]);
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $game->id, 'enabled' => false]);
        $this->postJson($this->path($org), [...$this->payload(), 'game_id' => $game->id])->assertConflict()->assertJsonPath('code', 'GAME_CONTEXT_UNAVAILABLE');
        $this->postJson($this->path($org, $opponent->id).'/snapshots', ['version' => $opponent->version, 'context_label' => 'Match', 'confirm_public_data' => true])->assertConflict();
        $this->getJson($this->path($org, $opponent->id))->assertOk();
        $this->patchJson($this->path($org, $opponent->id), ['version' => $opponent->version, 'status' => 'archived', 'confirm_public_data' => true])->assertOk();
    }

    public function test_mfa_and_confirmed_current_tenant_revision_are_required(): void
    {
        $org = $this->editor();
        $user = User::findOrFail(auth()->id());
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($this->path($org), $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->actingAsWithMfa($user);
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $org->id, 'actor_user_id' => $user->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->postJson($this->path($org), $this->payload())->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('external_opponents', 0);
    }

    public function test_snapshot_history_is_paginated_and_rejects_unbounded_or_unknown_filters(): void
    {
        $org = $this->editor();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $body = ['version' => $opponent->version, 'context_label' => 'Match', 'confirm_public_data' => true];
        $this->postJson($this->path($org, $opponent->id).'/snapshots', $body)->assertCreated();
        $this->postJson($this->path($org, $opponent->id).'/snapshots', [...$body, 'context_label' => 'Autre match'])->assertCreated();
        $this->getJson($this->path($org, $opponent->id).'/snapshots?per_page=1&page=2')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.current_page', 2);
        $this->getJson($this->path($org, $opponent->id).'/snapshots?per_page=101')->assertUnprocessable();
        $this->getJson($this->path($org, $opponent->id).'/snapshots?organization_id=spoof')->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    }

    public function test_reference_and_snapshot_limits_refuse_new_records_without_discarding_history(): void
    {
        $org = $this->editor();
        $opponent = ExternalOpponent::factory()->create(['organization_id' => $org->id]);
        $attributes = $opponent->getAttributes();
        $rows = [];
        for ($index = 1; $index < 1000; $index++) {
            $rows[] = [...$attributes, 'id' => (string) Str::uuid(), 'key' => 'reference-'.$index];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('external_opponents')->insert($chunk);
        }
        $this->postJson($this->path($org), $this->payload())->assertConflict()->assertJsonPath('code', 'EXTERNAL_OPPONENT_LIMIT_REACHED');
        $this->assertDatabaseCount('external_opponents', 1000);
        $body = ['version' => $opponent->version, 'context_label' => 'Match', 'confirm_public_data' => true];
        $response = $this->postJson($this->path($org, $opponent->id).'/snapshots', $body)->assertCreated();
        $snapshot = DB::table('external_opponent_snapshots')->where('id', $response->json('data.id'))->first();
        $this->assertNotNull($snapshot);
        $rows = [];
        for ($index = 1; $index < 1000; $index++) {
            $rows[] = [...(array) $snapshot, 'id' => (string) Str::uuid()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('external_opponent_snapshots')->insert($chunk);
        }
        $this->postJson($this->path($org, $opponent->id).'/snapshots', $body)->assertConflict()->assertJsonPath('code', 'EXTERNAL_OPPONENT_SNAPSHOT_LIMIT_REACHED');
        $this->assertDatabaseCount('external_opponent_snapshots', 1000);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['sports.read', 'teams.manage']): Organization
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $org;
    }

    private function path(Organization $org, ?string $id = null): string
    {
        return '/api/v1/tenants/'.$org->id.'/external-opponents'.($id === null ? '' : '/'.$id);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['key' => 'adversaire-pilote', 'kind' => 'team', 'name' => 'Équipe adverse', 'source_label' => 'Liste publique',
            'source_url' => 'https://example.test/teams', 'observed_at' => '2026-01-01T00:00:00+00:00', 'confirm_public_data' => true];
    }
}
