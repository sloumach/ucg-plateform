<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class GameProfileControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_cannot_read_game_profiles(): void
    {
        $person = Person::factory()->create();

        $this->getJson($this->path($person))->assertUnauthorized();
    }

    public function test_owner_without_sports_permission_cannot_create_a_profile(): void
    {
        $organization = Organization::factory()->withMfaOwner()->create();
        $person = Person::factory()->create(['organization_id' => $organization->id]);
        $this->actingAsWithMfa(User::findOrFail($organization->owner_user_id));

        $this->postJson($this->path($person), $this->payload())->assertForbidden();
        $this->assertDatabaseCount('person_game_profiles', 0);
    }

    public function test_profile_creation_returns_closed_sports_data_and_audit_without_rank_or_role_values(): void
    {
        $person = $this->editor();
        $response = $this->postJson($this->path($person), [...$this->payload(), 'rank' => 'PRIVATE_RANK', 'game_role' => 'PRIVATE_ROLE'])
            ->assertCreated()->assertJsonPath('data.game_key', 'valorant')->assertJsonPath('data.external_accounts_count', 0)
            ->assertJsonPath('data.freshness', 'unknown')->assertJsonPath('data.data_source', 'manual')
            ->assertJsonMissingPath('data.organization_id')->assertJsonMissingPath('data.first_name')
            ->assertJsonPath('notification.message', 'Le profil de jeu a été enregistré.');

        $this->assertDatabaseHas('person_game_profiles', ['id' => $response->json('data.id'), 'person_id' => $person->id, 'rank' => 'PRIVATE_RANK']);
        $event = DB::table('organization_audit_events')->first();
        $this->assertNotNull($event);
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('meta.request_id'));
        $this->assertSame($response->json('data.id'), json_decode($event->after, true)['game_profile_id']);
        $this->assertStringNotContainsString('PRIVATE_', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_one_person_can_have_multiple_games_and_regions_but_not_duplicate_contexts(): void
    {
        $person = $this->editor();
        $this->postJson($this->path($person), $this->payload())->assertCreated();
        $this->postJson($this->path($person), [...$this->payload(), 'game_key' => 'league-of-legends'])->assertCreated();
        $this->postJson($this->path($person), [...$this->payload(), 'region_key' => 'na'])->assertCreated();

        $this->postJson($this->path($person), $this->payload())->assertConflict()->assertJsonPath('code', 'GAME_PROFILE_ALREADY_EXISTS');
        $this->assertDatabaseCount('person_game_profiles', 3);
        $this->assertDatabaseCount('organization_audit_events', 3);
    }

    public function test_profile_pages_are_isolated_bounded_and_include_total_and_request_id(): void
    {
        $person = $this->editor(['sports.read']);
        GameProfile::factory()->count(3)->sequence(['region_key' => 'eu'], ['region_key' => 'na'], ['region_key' => 'apac'])
            ->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        GameProfile::factory()->count(2)->create();
        $response = $this->getJson($this->path($person).'?per_page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)->assertJsonPath('meta.per_page', 2);
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('meta.request_id'));
    }

    public function test_empty_person_profiles_return_a_successful_empty_page(): void
    {
        $person = $this->editor(['sports.read']);

        $this->getJson($this->path($person))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    public function test_foreign_person_profile_and_account_ids_return_404_even_for_members_of_both_tenants(): void
    {
        $person = $this->editor();
        $user = User::findOrFail(auth()->id());
        $foreign = ExternalGameAccount::factory()->create();
        $foreignProfile = GameProfile::findOrFail($foreign->game_profile_id);
        $foreignPerson = Person::findOrFail($foreignProfile->person_id);
        $this->grantPeopleAccess(Organization::findOrFail($foreign->organization_id), $user, ['sports.read', 'sports.write']);

        $this->getJson($this->path($person, $foreignProfile->id))->assertNotFound();
        $this->postJson($this->path($foreignPerson), $this->payload())->assertConflict();
        $this->postJson('/api/v1/tenants/'.$person->organization_id.'/people/'.$foreignPerson->id.'/game-profiles', $this->payload())->assertNotFound();
        $local = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        $this->getJson($this->accountsPath($person, $local, $foreign->id))->assertNotFound();
        $this->assertDatabaseCount('person_external_accounts', 1);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidProfiles(): array
    {
        return [
            'game key format' => [['game_key' => 'VALORANT'], 'game_key', 'Le format de ce champ est invalide.'],
            'platform type' => [['platform_key' => []], 'platform_key', 'Ce champ est obligatoire.'],
            'region format' => [['region_key' => 'EU WEST'], 'region_key', 'Le format de ce champ est invalide.'],
            'long rank' => [['rank' => str_repeat('x', 81)], 'rank', 'Ce champ ne doit pas dépasser 80 caractères.'],
            'long role' => [['game_role' => str_repeat('x', 81)], 'game_role', 'Ce champ ne doit pas dépasser 80 caractères.'],
            'invalid status' => [['status' => 'owner'], 'status', 'La valeur sélectionnée n’est pas autorisée.'],
            'date format' => [['observed_at' => 'yesterday'], 'observed_at', 'Utilisez une date ISO 8601 avec fuseau horaire et secondes.'],
            'future' => [['observed_at' => '2099-01-01T00:00:00+00:00'], 'observed_at', 'La date d’observation ne peut pas être future.'],
            'unknown key' => [['permissions' => ['*']], 'permissions', 'Ce champ ne peut pas être fourni.'],
            'spoof person' => [['person_id' => 'spoof'], 'person_id', 'Ce champ ne peut pas être fourni.'],
            'spoof tenant' => [['organization_id' => null], 'organization_id', 'Ce champ ne peut pas être fourni.'],
            'spoof version' => [['version' => 'spoof'], 'version', 'Ce champ ne peut pas être fourni.'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidProfiles')]
    public function test_invalid_profile_returns_localized_422_without_persistence(array $change, string $field, string $message): void
    {
        $person = $this->editor();

        $this->postJson($this->path($person), [...$this->payload(), ...$change])->assertUnprocessable()
            ->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('person_game_profiles', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_missing_profile_identity_returns_localized_422(): void
    {
        $person = $this->editor();

        $this->postJson($this->path($person), [])->assertUnprocessable()->assertJsonValidationErrors(['game_key', 'platform_key', 'region_key']);
        $this->assertDatabaseCount('person_game_profiles', 0);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidPatchControl(): array
    {
        return ['missing version' => [[], 'version'], 'invalid version' => [['version' => 'bad'], 'version'],
            'immutable null context' => [['game_key' => null], 'game_key']];
    }

    /** @param array<string, mixed> $control */
    #[DataProvider('invalidPatchControl')]
    public function test_patch_requires_valid_version_and_refuses_context_changes(array $control, string $field): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        $payload = ['rank' => 'Gold', ...$control];
        if ($field !== 'version') {
            $payload['version'] = $profile->version;
        }

        $this->patchJson($this->path($person, $profile->id), $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseHas('person_game_profiles', ['id' => $profile->id, 'version' => $profile->version, 'rank' => null]);
    }

    public function test_profile_patch_preserves_omitted_values_and_resets_observation_after_rank_change(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id,
            'rank' => 'Silver', 'game_role' => 'Support', 'observed_at' => now()->subDay()]);

        $this->patchJson($this->path($person, $profile->id), ['rank' => 'Gold', 'version' => $profile->version])->assertOk()
            ->assertJsonPath('data.rank', 'Gold')->assertJsonPath('data.game_role', 'Support')->assertJsonPath('data.observed_at', null)
            ->assertJsonPath('data.freshness', 'unknown');
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_stale_profile_version_returns_409_without_changes(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);

        $this->patchJson($this->path($person, $profile->id), ['rank' => 'Gold', 'version' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'GAME_PROFILE_VERSION_STALE');
        $this->assertDatabaseHas('person_game_profiles', ['id' => $profile->id, 'rank' => null, 'version' => $profile->version]);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_external_account_creation_does_not_claim_ownership_or_log_stable_identifier(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        $response = $this->postJson($this->accountsPath($person, $profile), ['provider_key' => 'riot', 'external_id' => 'SECRET-STABLE-ID', 'display_name' => 'SECRET-NAME'])
            ->assertCreated()->assertJsonPath('data.external_id', 'SECRET-STABLE-ID')->assertJsonPath('data.ownership_verified', false)
            ->assertJsonPath('data.data_source', 'manual')->assertJsonPath('data.freshness', 'unknown');

        $this->assertDatabaseHas('person_external_accounts', ['id' => $response->json('data.id'), 'game_profile_id' => $profile->id]);
        $event = DB::table('organization_audit_events')->first();
        $this->assertNotNull($event);
        $this->assertStringNotContainsString('SECRET-', json_encode($event, JSON_THROW_ON_ERROR));
        $this->assertDatabaseCount('organization_memberships', 1);
        $this->assertDatabaseCount('organization_role_assignments', 1);
    }

    public function test_accounts_without_stable_id_can_share_display_name_and_be_enriched_once(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        $first = $this->postJson($this->accountsPath($person, $profile), ['provider_key' => 'riot', 'display_name' => 'Camille#EU'])->assertCreated()->json('data');
        $this->postJson($this->accountsPath($person, $profile), ['provider_key' => 'riot', 'display_name' => 'Camille#EU'])->assertCreated();

        $enriched = $this->patchJson($this->accountsPath($person, $profile, $first['id']), ['external_id' => 'Stable-ABC', 'version' => $first['version']])
            ->assertOk()->assertJsonPath('data.external_id', 'Stable-ABC')->json('data');
        $this->patchJson($this->accountsPath($person, $profile, $first['id']), ['external_id' => 'Other-ID', 'version' => $enriched['version']])
            ->assertConflict()->assertJsonPath('code', 'EXTERNAL_ACCOUNT_IDENTITY_IMMUTABLE');
        $this->assertDatabaseCount('person_external_accounts', 2);
    }

    public function test_duplicate_stable_id_is_rejected_only_in_the_same_complete_context(): void
    {
        $person = $this->editor();
        $otherPerson = Person::factory()->create(['organization_id' => $person->organization_id]);
        $first = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        $second = GameProfile::factory()->create(['person_id' => $otherPerson->id, 'organization_id' => $person->organization_id]);
        $payload = ['provider_key' => 'riot', 'external_id' => 'Stable-ABC'];
        $this->postJson($this->accountsPath($person, $first), $payload)->assertCreated();

        $this->postJson($this->accountsPath($otherPerson, $second), $payload)->assertConflict()->assertJsonPath('code', 'EXTERNAL_ACCOUNT_ALREADY_LINKED');
        $this->postJson($this->accountsPath($person, $first), [...$payload, 'external_id' => 'stable-abc'])->assertCreated();
        $third = GameProfile::factory()->create(['person_id' => $otherPerson->id, 'organization_id' => $person->organization_id, 'region_key' => 'na']);
        $this->postJson($this->accountsPath($otherPerson, $third), $payload)->assertCreated();
        $this->assertDatabaseCount('person_external_accounts', 3);
    }

    public function test_external_account_patch_detects_stale_version_and_keeps_omitted_identifier(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        $account = ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id]);
        $this->patchJson($this->accountsPath($person, $profile, $account->id), ['display_name' => 'Nouveau pseudo', 'version' => $account->version])
            ->assertOk()->assertJsonPath('data.external_id', $account->external_id)->assertJsonPath('data.display_name', 'Nouveau pseudo');

        $this->patchJson($this->accountsPath($person, $profile, $account->id), ['status' => 'archived', 'version' => $account->version])
            ->assertConflict()->assertJsonPath('code', 'EXTERNAL_ACCOUNT_VERSION_STALE');
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidAccounts(): array
    {
        return [
            'provider format' => [['provider_key' => 'RIOT'], 'provider_key', 'Le format de ce champ est invalide.'],
            'id whitespace' => [['external_id' => 'id with spaces'], 'external_id', 'Le format de ce champ est invalide.'],
            'id length' => [['external_id' => str_repeat('x', 192)], 'external_id', 'Ce champ ne doit pas dépasser 191 caractères.'],
            'id object' => [['external_id' => ['secret']], 'external_id', 'Ce champ doit être un texte.'],
            'name length' => [['display_name' => str_repeat('x', 121)], 'display_name', 'Ce champ ne doit pas dépasser 120 caractères.'],
            'invalid status' => [['status' => 'verified'], 'status', 'La valeur sélectionnée n’est pas autorisée.'],
            'future' => [['observed_at' => '2099-01-01T00:00:00+00:00'], 'observed_at', 'La date d’observation ne peut pas être future.'],
            'password' => [['password' => 'secret'], 'password', 'Ce champ ne peut pas être fourni.'],
            'ownership spoof' => [['ownership_verified' => true], 'ownership_verified', 'Ce champ ne peut pas être fourni.'],
            'move profile' => [['game_profile_id' => 'spoof'], 'game_profile_id', 'Ce champ ne peut pas être fourni.'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidAccounts')]
    public function test_invalid_external_account_returns_localized_422_without_persistence(array $change, string $field, string $message): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);

        $this->postJson($this->accountsPath($person, $profile), ['provider_key' => 'riot', 'external_id' => 'valid-id', ...$change])
            ->assertUnprocessable()->assertJsonPath('errors.'.$field.'.0', $message);
        $this->assertDatabaseCount('person_external_accounts', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_external_account_requires_a_stable_id_or_display_name(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);

        $this->postJson($this->accountsPath($person, $profile), ['provider_key' => 'riot'])->assertUnprocessable()
            ->assertJsonPath('errors.external_id.0', 'Renseignez un identifiant stable ou un nom d’affichage.');
        $this->assertDatabaseCount('person_external_accounts', 0);
    }

    public function test_account_pages_are_scoped_to_the_requested_profile(): void
    {
        $person = $this->editor(['sports.read']);
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        ExternalGameAccount::factory()->count(3)->create(['game_profile_id' => $profile->id]);
        ExternalGameAccount::factory()->create();

        $response = $this->getJson($this->accountsPath($person, $profile).'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 3);
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('meta.request_id'));
    }

    public function test_disabled_game_or_platform_blocks_new_use_but_history_is_readable_and_archivable(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id]);
        GameCatalogueEntry::query()->where('kind', 'platform')->where('key', 'pc')->update(['active' => false]);

        $this->postJson($this->path($person), [...$this->payload(), 'region_key' => 'na'])->assertConflict()->assertJsonPath('code', 'GAME_CONTEXT_UNAVAILABLE');
        $this->getJson($this->path($person, $profile->id))->assertOk()->assertJsonPath('data.game_key', 'valorant');
        $this->patchJson($this->path($person, $profile->id), ['status' => 'archived', 'version' => $profile->version])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseCount('person_game_profiles', 1);
    }

    public function test_catalogue_supports_adding_and_removing_references_and_tenant_activation_overrides(): void
    {
        $person = $this->editor(['sports.read']);
        $custom = GameCatalogueEntry::factory()->create(['key' => 'custom-game', 'name' => 'Jeu supplémentaire']);
        foreach (GameCatalogueEntry::query()->where('kind', 'game')->get() as $game) {
            OrganizationGame::factory()->create(['organization_id' => $person->organization_id, 'game_id' => $game->id, 'enabled' => $game->key === 'valorant']);
        }

        $this->getJson('/api/v1/tenants/'.$person->organization_id.'/game-profile-catalogue')->assertOk()->assertJsonCount(1, 'data.games')->assertJsonPath('data.games.0.key', 'valorant');
        OrganizationGame::query()->where('organization_id', $person->organization_id)->update(['enabled' => false]);
        OrganizationGame::query()->where('organization_id', $person->organization_id)->where('game_id', $custom->id)->update(['enabled' => true]);
        $this->getJson('/api/v1/tenants/'.$person->organization_id.'/game-profile-catalogue')->assertOk()->assertJsonPath('data.games.0.key', 'custom-game');
        $custom->forceFill(['definition' => ['platforms' => ['unknown-platform' => ['steam']], 'regions' => ['eu']]])->save();
        $this->getJson('/api/v1/tenants/'.$person->organization_id.'/game-profile-catalogue')->assertConflict()->assertJsonPath('code', 'GAME_CATALOGUE_INVALID');
        $custom->forceFill(['definition' => ['platforms' => ['pc' => ['steam']], 'regions' => ['eu']]])->save();
        GameCatalogueEntry::query()->where('kind', 'platform')->where('key', 'pc')->update(['active' => false]);
        $this->getJson('/api/v1/tenants/'.$person->organization_id.'/game-profile-catalogue')->assertOk()->assertJsonCount(0, 'data.games');
    }

    public function test_archived_profile_rejects_new_accounts_and_allows_existing_account_archival(): void
    {
        $person = $this->editor();
        $profile = GameProfile::factory()->create(['person_id' => $person->id, 'organization_id' => $person->organization_id, 'status' => 'archived']);
        $account = ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id]);

        $this->postJson($this->accountsPath($person, $profile), ['provider_key' => 'riot', 'external_id' => 'new-id'])->assertConflict()->assertJsonPath('code', 'GAME_PROFILE_ARCHIVED');
        $this->patchJson($this->accountsPath($person, $profile, $account->id), ['status' => 'archived', 'version' => $account->version])->assertOk()->assertJsonPath('data.status', 'archived');
        $this->assertDatabaseCount('person_external_accounts', 1);
    }

    public function test_mfa_and_tenant_confirmation_are_required_for_privileged_mutations(): void
    {
        $person = $this->editor();
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($this->path($person), $this->payload())->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->actingAsWithMfa(User::findOrFail(auth()->id()));
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $person->organization_id, 'actor_user_id' => auth()->id(), 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);

        $this->postJson($this->path($person), $this->payload())->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('person_game_profiles', 0);
    }

    public function test_technical_operator_does_not_gain_business_access_through_a_grant(): void
    {
        $person = $this->editor();
        PlatformRole::factory()->create(['user_id' => auth()->id()]);

        $this->getJson($this->path($person))->assertForbidden();
        $this->assertDatabaseCount('person_game_profiles', 0);
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['sports.read', 'sports.write']): Person
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, $permissions);
        $this->actingAsWithMfa($user);

        return Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu'];
    }

    private function path(Person $person, ?string $profileId = null): string
    {
        return '/api/v1/tenants/'.$person->organization_id.'/people/'.$person->id.'/game-profiles'.($profileId === null ? '' : '/'.$profileId);
    }

    private function accountsPath(Person $person, GameProfile $profile, ?string $accountId = null): string
    {
        return $this->path($person, $profile->id).'/external-accounts'.($accountId === null ? '' : '/'.$accountId);
    }
}
