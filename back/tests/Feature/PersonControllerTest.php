<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_guest_returns_401_without_creating_a_person(): void
    {
        $organization = Organization::factory()->create();

        $this->postJson($this->path($organization), $this->personPayload())->assertUnauthorized();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_owner_without_explicit_business_rights_returns_403(): void
    {
        $organization = Organization::factory()->withMfaOwner()->create();
        $this->actingAsWithMfa(User::findOrFail($organization->owner_user_id));

        $this->postJson($this->path($organization), $this->personPayload())->assertForbidden();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_valid_payload_creates_a_person_without_account_and_a_private_data_free_audit(): void
    {
        $organization = $this->editor();
        $payload = [...$this->personPayload(), 'first_name' => 'SECRET_IDENTITY', 'biography_internal' => 'SECRET_NOTE'];

        $response = $this->postJson($this->path($organization), $payload)->assertCreated()
            ->assertJsonPath('data.first_name', 'SECRET_IDENTITY')->assertJsonPath('data.user_id', null)
            ->assertJsonPath('notification.message', 'La fiche personne a été créée.');

        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseHas('people', ['id' => $response->json('data.id'), 'organization_id' => $organization->id, 'user_id' => null]);
        $row = DB::table('people')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('SECRET_IDENTITY', $row->first_name);
        $this->assertNotSame('SECRET_NOTE', $row->biography_internal);
        $event = DB::table('organization_audit_events')->first();
        $this->assertNotNull($event);
        $this->assertSame('domain.changed', $event->action);
        $this->assertSame($organization->id, $event->target_id);
        $this->assertSame($response->json('data.id'), json_decode($event->after, true)['person_id']);
        $this->assertSame($response->headers->get('X-Request-ID'), $event->request_id);
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('meta.request_id'));
        $this->assertStringNotContainsString('SECRET_', json_encode($event, JSON_THROW_ON_ERROR));
    }

    public function test_sports_reader_gets_no_account_identity_contact_or_internal_biography(): void
    {
        $organization = $this->editor(['sports.read']);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);

        $response = $this->getJson($this->path($organization, $person->id))->assertOk()
            ->assertJsonPath('data.pseudonym', $person->pseudonym);
        $this->assertSame(['id', 'version', 'pseudonym', 'language', 'timezone', 'status', 'biography_public'], array_keys($response->json('data')));
        $this->assertStringNotContainsString('confidentielle', json_encode($response->json('data'), JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('first_name', $person->toArray());
        $this->assertArrayNotHasKey('user_id', $person->toArray());
    }

    public function test_administrative_reader_cannot_obtain_sports_fields_without_sports_permission(): void
    {
        $organization = $this->editor(['administrative.read']);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);

        $response = $this->getJson($this->path($organization, $person->id))->assertOk()
            ->assertJsonPath('data.first_name', 'Camille')->assertJsonMissingPath('data.pseudonym');
        $this->assertArrayNotHasKey('organization_id', $response->json('data'));
    }

    public function test_sports_editor_cannot_write_even_a_null_administrative_field(): void
    {
        $organization = $this->editor(['sports.read', 'sports.write']);

        $this->postJson($this->path($organization), [...$this->personPayload(), 'first_name' => null])->assertForbidden();
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_sports_patch_preserves_hidden_administrative_data_and_changes_version(): void
    {
        $organization = $this->editor(['sports.read', 'sports.write']);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);

        $this->patchJson($this->path($organization, $person->id), ['pseudonym' => 'Nouveau pseudo', 'version' => $person->version])
            ->assertOk()->assertJsonPath('data.pseudonym', 'Nouveau pseudo')->assertJsonMissingPath('data.first_name')
            ->assertJsonPath('notification.message', 'La fiche personne a été mise à jour.');

        $this->assertSame('Camille', $person->refresh()->first_name);
        $this->assertSame('Note interne confidentielle', $person->biography_internal);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_administrative_patch_can_clear_private_fields_without_sports_write(): void
    {
        $organization = $this->editor(['administrative.read', 'administrative.write']);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id]);

        $this->patchJson($this->path($organization, $person->id), ['contact_email' => null, 'version' => $person->version])
            ->assertOk()->assertJsonPath('data.contact_email', null)->assertJsonMissingPath('data.pseudonym');

        $this->assertNull($person->refresh()->contact_email);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_stale_patch_returns_409_without_overwriting_or_auditing(): void
    {
        $organization = $this->editor();
        $person = Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Original']);

        $this->patchJson($this->path($organization, $person->id), ['pseudonym' => 'Écrasé', 'version' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('code', 'PERSON_VERSION_STALE');

        $this->assertDatabaseHas('people', ['id' => $person->id, 'pseudonym' => 'Original', 'version' => $person->version]);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    /** @return array<string, array{array<string, mixed>, string, string}> */
    public static function invalidPayloads(): array
    {
        return [
            'blank pseudonym' => [['pseudonym' => '  '], 'pseudonym', 'Ce champ est obligatoire.'],
            'long pseudonym' => [['pseudonym' => str_repeat('x', 81)], 'pseudonym', 'Ce champ ne doit pas dépasser 80 caractères.'],
            'array pseudonym' => [['pseudonym' => ['x']], 'pseudonym', 'Ce champ doit être un texte.'],
            'language' => [['language' => 'invalid'], 'language', 'La valeur sélectionnée n’est pas autorisée.'],
            'timezone' => [['timezone' => 'Mars/Olympus'], 'timezone', 'Le fuseau horaire est invalide.'],
            'status' => [['status' => 'owner'], 'status', 'La valeur sélectionnée n’est pas autorisée.'],
            'public bio size' => [['biography_public' => str_repeat('x', 4001)], 'biography_public', 'Ce champ ne doit pas dépasser 4000 caractères.'],
            'internal bio type' => [['biography_internal' => []], 'biography_internal', 'Ce champ doit être un texte.'],
            'first name size' => [['first_name' => str_repeat('x', 121)], 'first_name', 'Ce champ ne doit pas dépasser 120 caractères.'],
            'last name size' => [['last_name' => str_repeat('x', 121)], 'last_name', 'Ce champ ne doit pas dépasser 120 caractères.'],
            'email' => [['contact_email' => 'not-an-email'], 'contact_email', 'L’adresse e-mail est invalide.'],
            'phone' => [['contact_phone' => '<script>'], 'contact_phone', 'Le format de ce champ est invalide.'],
            'negative account' => [['user_id' => -1], 'user_id', 'La valeur doit être au moins 1.'],
            'array account' => [['user_id' => []], 'user_id', 'Ce champ doit être un entier.'],
            'spoofed tenant' => [['organization_id' => 'spoof'], 'organization_id', 'Ce champ ne peut pas être fourni.'],
            'spoofed id' => [['id' => 'spoof'], 'id', 'Ce champ ne peut pas être fourni.'],
            'unknown key' => [['permissions' => ['*']], 'permissions', 'Ce champ ne peut pas être fourni.'],
            'version on create' => [['version' => 'spoof'], 'version', 'Ce champ ne peut pas être fourni.'],
        ];
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_create_returns_422_with_localized_message_without_persistence(array $overrides, string $field, string $message): void
    {
        $organization = $this->editor();

        $this->postJson($this->path($organization), [...$this->personPayload(), ...$overrides])
            ->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonPath('errors.'.$field.'.0', $message);

        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_empty_create_requires_the_four_core_fields(): void
    {
        $organization = $this->editor();

        $this->postJson($this->path($organization), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['pseudonym', 'language', 'timezone', 'status']);
        $this->assertDatabaseCount('people', 0);
    }

    public function test_empty_patch_returns_422_without_new_version_or_audit(): void
    {
        $organization = $this->editor();
        $person = Person::factory()->create(['organization_id' => $organization->id]);

        $this->patchJson($this->path($organization, $person->id), ['version' => $person->version])
            ->assertUnprocessable()->assertJsonPath('errors.person.0', 'Indiquez au moins un champ à modifier.');
        $this->assertDatabaseHas('people', ['id' => $person->id, 'version' => $person->version]);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_person_returns_404_even_if_actor_belongs_to_both_tenants(): void
    {
        $organization = $this->editor(['sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $user = User::findOrFail(auth()->id());
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read', 'sports.write']);
        $person = Person::factory()->create(['organization_id' => $other->id]);

        $denied = $this->getJson($this->path($organization, $person->id))->assertNotFound();
        $missing = $this->getJson($this->path($organization, (string) Str::uuid()))->assertNotFound();
        $this->assertSame($missing->json('code'), $denied->json('code'));
        $this->assertSame($missing->json('message'), $denied->json('message'));
        $this->assertStringNotContainsString($person->id, (string) $denied->getContent());
        $this->assertStringNotContainsString($other->id, (string) $denied->getContent());
        $this->patchJson($this->path($organization, $person->id), ['pseudonym' => 'Déplacé', 'version' => $person->version])->assertNotFound();
        $this->postJson($this->path($organization, $person->id).'/departure', ['version' => $person->version, 'reason_key' => 'other', 'confirm' => true])->assertNotFound();
        $this->assertDatabaseHas('people', ['id' => $person->id, 'organization_id' => $other->id, 'version' => $person->version]);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('person_departures', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    public function test_revocation_after_person_reads_blocks_alpha_writes_without_revoking_beta(string $revocation, int $status): void
    {
        $organization = $this->editor(['sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $user = User::findOrFail(auth()->id());
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read', 'sports.write']);
        $person = Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Alpha private']);
        $beta = Person::factory()->create(['organization_id' => $other->id, 'pseudonym' => 'Beta private']);
        $this->getJson($this->path($organization))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path($organization, $person->id))->assertOk()->assertJsonPath('data.pseudonym', 'Alpha private');
        if ($revocation === 'membership') {
            OrganizationMembership::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } else {
            OrganizationRoleAssignment::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        }

        $this->getJson($this->path($organization))->assertStatus($status)->assertJsonMissingPath('data');
        $this->getJson($this->path($organization, $person->id))->assertStatus($status)->assertJsonMissingPath('data');
        $this->postJson($this->path($organization), $this->personPayload())->assertStatus($status);
        $this->patchJson($this->path($organization, $person->id), ['version' => $person->version, 'pseudonym' => 'Hijacked'])->assertStatus($status);
        $this->postJson($this->path($organization, $person->id).'/departure', ['version' => $person->version, 'reason_key' => 'other', 'confirm' => true])->assertStatus($status);

        $this->assertDatabaseHas('people', ['id' => $person->id, 'version' => $person->version, 'pseudonym' => 'Alpha private', 'status' => 'active']);
        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseCount('person_departures', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
        $this->getJson($this->path($other, $beta->id))->assertOk()->assertJsonPath('data.pseudonym', 'Beta private');
        $this->patchJson($this->path($other, $beta->id), ['version' => $beta->version, 'pseudonym' => 'Beta authorized'])->assertOk();
        $this->assertDatabaseHas('organization_audit_events', ['organization_id' => $other->id]);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    #[Group('tenant-isolation')]
    public function test_removing_sports_write_keeps_reads_and_administrative_edits_but_blocks_sports_writes(): void
    {
        $organization = $this->editor();
        $user = User::findOrFail(auth()->id());
        $person = Person::factory()->create(['organization_id' => $organization->id, 'pseudonym' => 'Preserved']);
        $this->getJson($this->path($organization, $person->id))->assertOk();
        OrganizationRoleAssignment::query()->where('organization_id', $organization->id)->where('user_id', $user->id)->firstOrFail()
            ->role->forceFill(['permissions' => ['sports.read', 'administrative.read', 'administrative.write']])->save();

        $this->getJson($this->path($organization, $person->id))->assertOk()->assertJsonPath('data.pseudonym', 'Preserved');
        $this->postJson($this->path($organization), $this->personPayload())->assertForbidden();
        $this->patchJson($this->path($organization, $person->id), ['version' => $person->version, 'pseudonym' => 'Hijacked'])->assertForbidden();
        $this->assertDatabaseHas('people', ['id' => $person->id, 'version' => $person->version, 'pseudonym' => 'Preserved']);
        $this->assertDatabaseCount('people', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);

        $this->patchJson($this->path($organization, $person->id), ['version' => $person->version, 'contact_email' => 'authorized@example.test'])
            ->assertOk()->assertJsonPath('data.contact_email', 'authorized@example.test');
        $this->assertDatabaseHas('people', ['id' => $person->id, 'pseudonym' => 'Preserved']);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_same_global_account_links_distinct_profiles_in_two_tenants_without_granting_rights(): void
    {
        $organization = $this->editor();
        $user = User::findOrFail(auth()->id());
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $subject = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $subject->id]);
        OrganizationMembership::factory()->create(['organization_id' => $other->id, 'user_id' => $subject->id]);

        $first = $this->postJson($this->path($organization), [...$this->personPayload(), 'user_id' => $subject->id, 'first_name' => 'Tenant A'])
            ->assertCreated()->json('data.id');
        $second = $this->postJson($this->path($other), [...$this->personPayload(), 'user_id' => $subject->id, 'first_name' => 'Tenant B'])
            ->assertCreated()->json('data.id');
        $this->assertNotSame($first, $second);
        $this->getJson($this->path($organization, $first))->assertOk()->assertJsonPath('data.first_name', 'Tenant A');
        $this->assertDatabaseCount('organization_role_assignments', 2);
        $this->assertDatabaseCount('organization_memberships', 4);
    }

    public function test_duplicate_account_returns_409_but_identical_pseudonyms_without_account_are_allowed(): void
    {
        $organization = $this->editor();
        $subject = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);
        Person::factory()->create(['organization_id' => $organization->id, 'user_id' => $subject->user_id, 'pseudonym' => 'Camille']);

        $this->postJson($this->path($organization), [...$this->personPayload(), 'user_id' => $subject->user_id])
            ->assertConflict()->assertJsonPath('code', 'PERSON_ACCOUNT_ALREADY_LINKED');
        $this->postJson($this->path($organization), $this->personPayload())->assertCreated();
        $this->assertDatabaseCount('people', 2);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    /** @return array<string, array{string}> */
    public static function unavailableAccounts(): array
    {
        return ['absent' => ['absent'], 'foreign' => ['foreign'], 'unverified' => ['unverified'],
            'suspended' => ['suspended'], 'expired' => ['expired'], 'future' => ['future']];
    }

    #[DataProvider('unavailableAccounts')]
    public function test_unavailable_link_returns_same_409_without_identity_disclosure_or_persistence(string $kind): void
    {
        $organization = $this->editor();
        $user = $kind === 'unverified' ? User::factory()->unverified()->create() : User::factory()->create();
        if ($kind !== 'absent') {
            OrganizationMembership::factory()->create(['organization_id' => $kind === 'foreign' ? Organization::factory()->create()->id : $organization->id,
                'user_id' => $user->id, 'status' => $kind === 'suspended' ? 'suspended' : 'active',
                'starts_at' => $kind === 'future' ? now()->addDay() : now()->subDay(),
                'ends_at' => $kind === 'expired' ? now()->subHour() : null]);
        }

        $this->postJson($this->path($organization), [...$this->personPayload(), 'user_id' => $kind === 'absent' ? 99999999 : $user->id])
            ->assertConflict()->assertJsonPath('code', 'PERSON_ACCOUNT_UNAVAILABLE')
            ->assertJsonPath('message', 'Ce compte ne peut pas être lié dans cette organisation.');
        $this->assertDatabaseCount('people', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_technical_account_with_tenant_grant_returns_403(): void
    {
        $organization = $this->editor();
        PlatformRole::factory()->create(['user_id' => auth()->id()]);

        $this->postJson($this->path($organization), $this->personPayload())->assertForbidden();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_privileged_editor_without_session_mfa_returns_403(): void
    {
        $organization = $this->editor();
        $this->withSession([MfaSession::PROOF_KEY => null]);

        $this->postJson($this->path($organization), $this->personPayload())->assertForbidden();
        $this->assertDatabaseCount('people', 0);
    }

    public function test_unconfirmed_tenant_selection_returns_409(): void
    {
        $organization = $this->editor();
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $organization->id,
            'actor_user_id' => auth()->id(), 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);

        $this->postJson($this->path($organization), $this->personPayload())->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->assertDatabaseCount('people', 0);
    }

    public function test_audit_write_failure_returns_500_and_rolls_back_person_creation(): void
    {
        $organization = $this->editor();
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION reject_person_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'Audit unavailable'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER reject_person_audit BEFORE INSERT ON organization_audit_events FOR EACH ROW EXECUTE FUNCTION reject_person_audit()");
        } else {
            DB::unprepared("CREATE TRIGGER reject_person_audit BEFORE INSERT ON organization_audit_events BEGIN SELECT RAISE(ABORT, 'Audit unavailable'); END");
        }
        try {
            $this->postJson($this->path($organization), $this->personPayload())->assertInternalServerError()->assertJsonMissingPath('data');
            $this->assertDatabaseCount('people', 0);
            $this->assertDatabaseCount('organization_audit_events', 0);
        } finally {
            DB::unprepared('DROP TRIGGER reject_person_audit'.(DB::getDriverName() === 'pgsql' ? ' ON organization_audit_events' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION reject_person_audit()');
            }
        }
    }

    /** @param list<string> $permissions */
    private function editor(array $permissions = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']): Organization
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, $permissions);
        $this->actingAsWithMfa($user);

        return $organization;
    }

    private function path(Organization $organization, ?string $personId = null): string
    {
        return '/api/v1/tenants/'.$organization->id.'/people'.($personId === null ? '' : '/'.$personId);
    }
}
