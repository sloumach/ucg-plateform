<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Data\PersonArchiveData;
use App\Modules\People\Application\Services\PersonArchiveService;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonArchiveTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_logical_removal_preserves_identity_and_history_and_restore_does_not_cancel_departure(): void
    {
        [$organization, $person] = $this->scenario();
        $profile = GameProfile::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id]);
        $before = (array) DB::table('people')->where('id', $person->id)->first();
        $response = $this->deleteJson($this->path($organization, $person), ['version' => $person->version, 'confirm' => true])->assertOk()
            ->assertJsonPath('data.retention', 'indefinite')->assertJsonPath('data.physical_erasure', false)
            ->assertJsonPath('notification.message', __('people.archived'));
        $this->assertSoftDeleted('people', ['id' => $person->id]);
        $this->assertNull(Person::find($person->id));
        $after = (array) DB::table('people')->where('id', $person->id)->first();
        foreach (array_diff_key($before, array_flip(['version', 'updated_at', 'deleted_at'])) as $key => $value) {
            $this->assertSame($value, $after[$key], $key.' must remain unchanged.');
        }
        $this->assertModelExists($profile);
        $this->getJson($this->path($organization, $person))->assertNotFound();
        $this->getJson($this->path($organization))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($organization).'?archive=only')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id);
        $this->getJson('/api/v1/tenants/'.$organization->id.'/search?type=people&q=Archive')->assertOk()->assertJsonCount(0, 'data.items');
        $version = $response->json('data.version');
        $this->postJson($this->path($organization, $person).'/restore', ['version' => $version, 'confirm' => true])->assertOk()
            ->assertJsonPath('data.deleted_at', null)->assertJsonPath('notification.message', __('people.restored'));
        $this->assertNotSoftDeleted('people', ['id' => $person->id]);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'former']);
        $this->assertDatabaseHas('person_departures', ['person_id' => $person->id]);
        $this->assertDatabaseCount('organization_audit_events', 2);
        $audit = DB::table('organization_audit_events')->orderBy('id')->get()->toJson();
        $this->assertStringNotContainsString('PRIVATE_ARCHIVE_NAME', $audit);
        $this->assertStringNotContainsString('PRIVATE_ARCHIVE_BIO', $audit);
    }

    /** @return array<string, array{list<string>}> */
    public static function insufficientPermissions(): array
    {
        return ['reader' => [['sports.read', 'administrative.read']], 'sports writer' => [['sports.read', 'sports.write']],
            'administrative writer' => [['administrative.read', 'administrative.write']]];
    }

    /** @param list<string> $permissions */
    #[DataProvider('insufficientPermissions')]
    public function test_logical_removal_restoration_and_archive_listing_require_both_write_scopes(array $permissions): void
    {
        [$organization, $person] = $this->scenario($permissions);
        $this->deleteJson($this->path($organization, $person), ['version' => $person->version, 'confirm' => true])->assertForbidden();
        $this->postJson($this->path($organization, $person).'/restore', ['version' => $person->version, 'confirm' => true])->assertForbidden();
        $this->getJson($this->path($organization).'?archive=only')->assertForbidden();
        $this->assertNotSoftDeleted('people', ['id' => $person->id]);
    }

    #[Group('tenant-isolation')]
    public function test_foreign_person_cannot_be_hidden_or_restored_using_a_local_context(): void
    {
        [$organization] = $this->scenario();
        $foreign = Person::factory()->create(['status' => 'former']);
        PersonDeparture::factory()->create(['person_id' => $foreign->id, 'organization_id' => $foreign->organization_id]);
        $this->deleteJson($this->path($organization, $foreign), ['version' => $foreign->version, 'confirm' => true])->assertNotFound();
        $this->postJson($this->path($organization, $foreign).'/restore', ['version' => $foreign->version, 'confirm' => true])->assertNotFound();
        $this->assertNotSoftDeleted('people', ['id' => $foreign->id]);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    public function test_multi_organization_actor_cannot_mix_archived_lists_or_archive_and_restore_parents(): void
    {
        [$alpha, $local, $actor] = $this->scenario();
        $beta = Organization::factory()->create();
        $this->grantPeopleAccess($beta, $actor, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $foreign = Person::factory()->create(['organization_id' => $beta->id, 'status' => 'former', 'pseudonym' => 'BetaArchive']);
        PersonDeparture::factory()->create(['organization_id' => $beta->id, 'person_id' => $foreign->id]);
        $before = (array) DB::table('people')->where('id', $foreign->id)->firstOrFail();
        $this->deleteJson($this->path($alpha, $foreign), ['version' => $foreign->version, 'confirm' => true])->assertNotFound();
        $this->postJson($this->path($alpha, $foreign).'/restore', ['version' => $foreign->version, 'confirm' => true])->assertNotFound();
        $this->assertSame($before, (array) DB::table('people')->where('id', $foreign->id)->firstOrFail());
        $archived = $this->deleteJson($this->path($beta, $foreign), ['version' => $foreign->version, 'confirm' => true])->assertOk();
        $this->getJson($this->path($alpha).'?archive=only')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($beta).'?archive=only')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreign->id);
        $this->postJson($this->path($alpha, $foreign).'/restore', ['version' => $archived->json('data.version'), 'confirm' => true])->assertNotFound();
        $this->postJson($this->path($beta, $foreign).'/restore', ['version' => $archived->json('data.version'), 'confirm' => true])->assertOk();
        $this->assertNotSoftDeleted('people', ['id' => $foreign->id]);
        $this->assertNotSoftDeleted('people', ['id' => $local->id]);
        $this->assertDatabaseCount('tenant_erasure_operations', 0);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): array
    {
        return ['missing version' => [['confirm' => true]], 'missing confirmation' => [['version' => 'VERSION']],
            'unconfirmed' => [['version' => 'VERSION', 'confirm' => false]],
            'unknown field' => [['version' => 'VERSION', 'confirm' => true, 'force' => true]]];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('invalidPayloads')]
    public function test_inputs_are_validated_before_archive_mutation(array $payload): void
    {
        [$organization, $person] = $this->scenario();
        if (($payload['version'] ?? null) === 'VERSION') {
            $payload['version'] = $person->version;
        }
        $this->deleteJson($this->path($organization, $person), $payload)->assertUnprocessable();
        $this->assertNotSoftDeleted('people', ['id' => $person->id]);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    public function test_stale_versions_and_incomplete_departure_preserve_the_person(): void
    {
        [$organization, $person] = $this->scenario();
        $this->deleteJson($this->path($organization, $person), ['version' => (string) Str::uuid7(), 'confirm' => true])->assertConflict();
        PersonDepartureItem::factory()->create(['organization_id' => $organization->id, 'person_id' => $person->id, 'status' => 'open']);
        $this->deleteJson($this->path($organization, $person), ['version' => $person->version, 'confirm' => true])->assertConflict()
            ->assertJsonPath('code', 'PERSON_ARCHIVE_DEPARTURE_REQUIRED');
        $other = Person::factory()->create(['organization_id' => $organization->id, 'status' => 'former']);
        $this->deleteJson($this->path($organization, $other), ['version' => $other->version, 'confirm' => true])->assertConflict();
        $this->assertNotSoftDeleted('people', ['id' => $person->id]);
        $this->assertNotSoftDeleted('people', ['id' => $other->id]);
    }

    public function test_archive_is_present_in_private_portability_and_retention_review_without_enabling_erasure(): void
    {
        [$organization, $person, $user] = $this->scenario();
        $this->deleteJson($this->path($organization, $person), ['version' => $person->version, 'confirm' => true])->assertOk();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($organization->id, 100));
        $people = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'people'));
        $this->assertSame($person->id, $people[0]['data']['id']);
        $this->assertNotNull($people[0]['data']['deleted_at']);
        config(['retention.review_operators' => [$user->id => [$organization->id]]]);
        $this->assertSame(0, Artisan::call('organizations:retention-review', ['organization' => $organization->id, '--actor' => $user->id,
            '--reason' => 'Fixture archived records', '--section' => 'people']));
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('indefinite', $report['person_disposition']['retention']);
        $this->assertTrue($report['data'][0]['soft_deleted']);
        $this->assertFalse($report['data'][0]['automatic_erasure']);
        $this->assertFalse($report['deletion_supported']);
    }

    public function test_audit_failure_rolls_back_logical_removal(): void
    {
        [$organization, $person, $user] = $this->scenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Fixture audit unavailable'));
        try {
            app(PersonArchiveService::class)->change($this->personContext($organization, $user), $person->id, new PersonArchiveData($person->version, true), true);
            $this->fail('Audit failure must prevent logical removal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fixture audit unavailable', $exception->getMessage());
            $this->assertNotSoftDeleted('people', ['id' => $person->id]);
            $this->assertDatabaseHas('people', ['id' => $person->id, 'version' => $person->version]);
        }
    }

    public function test_soft_deleted_person_cannot_be_force_deleted_or_lost_on_migration_rollback(): void
    {
        [$organization, $person] = $this->scenario();
        $this->deleteJson($this->path($organization, $person), ['version' => $person->version, 'confirm' => true])->assertOk();
        $record = Person::withTrashed()->findOrFail($person->id);
        try {
            $record->forceDelete();
            $this->fail('Physical erasure requires a separate manual procedure.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PERSON_HISTORY_RETAINED', $exception->errorCode());
        }
        $migration = require database_path('migrations/2026_10_27_213817_add_soft_deletes_to_people_table.php');
        try {
            $migration->down();
            $this->fail('An archive cannot be silently made visible by rollback.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('Restore person archives explicitly', $exception->getMessage());
            $this->assertSoftDeleted('people', ['id' => $person->id]);
        }
    }

    /** @param list<string> $permissions
     * @return array{Organization, Person, User}
     */
    private function scenario(array $permissions = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']): array
    {
        $this->freezeTime();
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, $permissions);
        $this->actingAsWithMfa($user);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id, 'status' => 'former',
            'pseudonym' => 'ArchiveProfile', 'first_name' => 'PRIVATE_ARCHIVE_NAME', 'biography_internal' => 'PRIVATE_ARCHIVE_BIO']);
        PersonDeparture::factory()->create(['person_id' => $person->id, 'organization_id' => $organization->id]);

        return [$organization, $person, $user];
    }

    private function path(Organization $organization, ?Person $person = null): string
    {
        return '/api/v1/tenants/'.$organization->id.'/people'.($person === null ? '' : '/'.$person->id);
    }
}
