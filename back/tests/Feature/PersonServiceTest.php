<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Contracts\PeopleDirectory;
use App\Modules\People\Application\Data\PersonData;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonServiceTest extends TestCase
{
    use LazilyRefreshDatabase, ProvidesPeopleAccess;

    public function test_direct_service_calls_reject_unknown_fields_without_persisting(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write']);

        try {
            $this->app->make(PersonService::class)->create($this->personContext($organization, $user),
                new PersonData([...$this->personPayload(), 'organization_id' => $organization->id]));
            $this->fail('Service entry points must validate their own data.');
        } catch (ValidationException $exception) {
            $this->assertSame(['organization_id' => ['Ce champ ne peut pas être fourni.']], $exception->errors());
            $this->assertDatabaseCount('people', 0);
        }
    }

    public function test_direct_service_rechecks_permissions_after_membership_revocation(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write']);
        $context = $this->personContext($organization, $user);
        OrganizationMembership::query()->whereKey($grant->membership_id)->update(['status' => 'revoked']);

        try {
            $this->app->make(PersonService::class)->create($context, new PersonData($this->personPayload()));
            $this->fail('A captured context must not retain revoked rights.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('people', 0);
            $this->assertDatabaseCount('organization_audit_events', 0);
        }
    }

    public function test_direct_service_rechecks_organization_status_before_write(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write']);
        $context = $this->personContext($organization, $user);
        DB::table('organizations')->where('id', $organization->id)->update(['status' => 'suspended']);

        try {
            $this->app->make(PersonService::class)->create($context, new PersonData($this->personPayload()));
            $this->fail('A captured context must not bypass organization suspension.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('people', 0);
        }
    }

    public function test_public_directory_returns_a_filtered_snapshot_and_does_not_use_account_global_identity(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create(['name' => 'Global identity']);
        $this->grantPeopleAccess($organization, $user, ['sports.read']);
        $person = Person::factory()->withAdministrativeIdentity()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);

        $record = $this->app->make(PeopleDirectory::class)->find($this->personContext($organization, $user), $person->id);

        $this->assertSame($person->id, $record->fields['id']);
        $this->assertArrayNotHasKey('first_name', $record->fields);
        $this->assertArrayNotHasKey('user_id', $record->fields);
        $this->assertStringNotContainsString('Global identity', json_encode($record->fields, JSON_THROW_ON_ERROR));
    }

    public function test_guard_refuses_to_lock_outside_the_business_transaction(): void
    {
        $context = new TenantContext('0199cdf3-5a20-7000-8000-000000000001', 1, 'test', 'UTC', 'fr',
            '0199cdf3-5a20-7000-8000-000000000002');

        $this->expectException(\LogicException::class);
        $this->app->make(TenantBusinessGuard::class)->lock($context);
    }

    /** @return array<string, array{string}> */
    public static function personStatuses(): array
    {
        return ['candidate' => ['candidate'], 'trial' => ['trial'], 'active' => ['active'],
            'unavailable' => ['unavailable'], 'substitute' => ['substitute'], 'former' => ['former']];
    }

    #[DataProvider('personStatuses')]
    public function test_person_status_never_changes_memberships_or_permission_assignments(string $status): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $subject = OrganizationMembership::factory()->create(['organization_id' => $organization->id]);
        $person = Person::factory()->create(['organization_id' => $organization->id, 'user_id' => $subject->user_id]);
        $oldVersion = $person->version;

        $result = $this->app->make(PersonService::class)->update($this->personContext($organization, $user),
            $person->id, new PersonData(['status' => $status], $person->version));

        $this->assertSame($status, $result->fields['status']);
        $this->assertNotSame($oldVersion, $result->fields['version']);
        $this->assertDatabaseHas('organization_memberships', ['id' => $subject->id, 'status' => 'active']);
        $this->assertDatabaseCount('organization_role_assignments', 1);
        $this->assertDatabaseCount('organization_audit_events', 1);
    }

    public function test_unlinking_a_person_account_does_not_revoke_membership_or_grants(): void
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $grant = $this->grantPeopleAccess($organization, $user, ['sports.read', 'administrative.read', 'administrative.write']);
        $person = Person::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);

        $result = $this->app->make(PersonService::class)->update($this->personContext($organization, $user),
            $person->id, new PersonData(['user_id' => null], $person->version));

        $this->assertNull($result->fields['user_id']);
        $this->assertDatabaseHas('organization_memberships', ['id' => $grant->membership_id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_role_assignments', ['id' => $grant->id, 'status' => 'active']);
    }
}
