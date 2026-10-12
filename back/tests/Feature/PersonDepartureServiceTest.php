<?php

namespace Tests\Feature;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Application\Contracts\PersonDepartureParticipant;
use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Services\PersonDepartureService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonDepartureServiceTest extends TestCase
{
    use LazilyRefreshDatabase,ProvidesPeopleAccess;

    public function test_departure_revokes_private_file_access_without_touching_other_tenant_files(): void
    {
        Storage::fake('local');
        [$org,$actor,$person,$subject] = $this->editor();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $subject, ['sports.read']);
        $files = app(TenantArtifacts::class);
        $first = $files->store($this->personContext($org, $subject), 'private.txt', 'alpha');
        $second = $files->store($this->personContext($other, $subject), 'private.txt', 'beta');
        app(PersonDepartureService::class)->depart($this->personContext($org, $actor), $person->id, new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version));
        $this->assertSame('beta', $files->read($this->personContext($other, $subject), $second->id));
        $this->expectException(ModelNotFoundException::class);
        $files->read($this->personContext($org, $subject), $first->id);
    }

    public function test_audit_failure_rolls_back_departure_and_all_access_changes(): void
    {
        [$org,$actor,$person,$subject] = $this->editor();
        $period = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("CREATE FUNCTION hum004_reject_audit() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'simulated audit failure'; END; $$ LANGUAGE plpgsql;
                CREATE TRIGGER hum004_reject_audit BEFORE INSERT ON organization_audit_events FOR EACH ROW EXECUTE FUNCTION hum004_reject_audit()");
        } else {
            DB::unprepared("CREATE TRIGGER hum004_reject_audit BEFORE INSERT ON organization_audit_events BEGIN SELECT RAISE(ABORT,'simulated audit failure'); END");
        }
        try {
            try {
                app(PersonDepartureService::class)->depart($this->personContext($org, $actor), $person->id, new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version));
                $this->fail('Audit failure must roll back.');
            } catch (QueryException) {
                $this->assertDatabaseCount('person_departures', 0);
                $this->assertDatabaseHas('person_periods', ['id' => $period->id, 'status' => 'active']);
                $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'active', 'version' => $person->version]);
                $this->assertDatabaseHas('organization_memberships', ['organization_id' => $org->id, 'user_id' => $subject->id, 'status' => 'active']);
            }
        } finally {
            DB::unprepared('DROP TRIGGER hum004_reject_audit'.(DB::getDriverName() === 'pgsql' ? ' ON organization_audit_events' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION hum004_reject_audit()');
            }
        }
    }

    public function test_departure_closes_periods_revokes_only_this_tenant_and_is_idempotent(): void
    {
        $this->freezeTime();
        [$org,$actor,$person,$subject] = $this->editor();
        $other = Organization::factory()->create();
        $otherGrant = $this->grantPeopleAccess($other, $subject, ['sports.read']);
        $grant = OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $subject->id)->firstOrFail();
        $future = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'starts_at' => now()->addDay()]);
        $running = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'role_key' => 'coach']);
        $invite = OrganizationInvitation::factory()->create(['organization_id' => $org->id, 'email' => $subject->email]);
        $context = $this->personContext($org, $actor);
        $body = new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version);
        $service = app(PersonDepartureService::class);
        $result = $service->depart($context, $person->id, $body);
        $this->assertTrue($result['departed']);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'user_id' => $subject->id, 'status' => 'former']);
        $this->assertDatabaseHas('person_periods', ['id' => $future->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('person_periods', ['id' => $running->id, 'status' => 'closed']);
        $this->assertDatabaseHas('organization_memberships', ['id' => $grant->membership_id, 'status' => 'revoked']);
        $this->assertDatabaseHas('organization_role_assignments', ['id' => $grant->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('organization_invitations', ['id' => $invite->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('organization_memberships', ['id' => $otherGrant->membership_id, 'status' => 'active']);
        $this->assertDatabaseHas('organization_role_assignments', ['id' => $otherGrant->id, 'status' => 'active']);
        $authorization = app(TenantAuthorization::class);
        $this->assertFalse($authorization->allows($this->personContext($org, $subject), 'sports.read'));
        $this->assertTrue($authorization->allows($this->personContext($other, $subject), 'sports.read'));
        $events = DB::table('organization_audit_events')->count() + DB::table('organization_access_events')->count();
        $this->assertSame($result, $service->depart($context, $person->id, $body));
        $this->assertSame($events, DB::table('organization_audit_events')->count() + DB::table('organization_access_events')->count());
        $this->assertDatabaseCount('person_departures', 1);
    }

    public function test_self_departure_commits_without_attempting_to_read_after_revocation(): void
    {
        [$org,$actor,$person] = $this->editor(true);
        $result = app(PersonDepartureService::class)->depart($this->personContext($org, $actor), $person->id, new PersonLifecycleData(['reason_key' => 'resignation', 'confirm' => true], $person->version));
        $this->assertTrue($result['departed']);
        $this->assertFalse(app(TenantAuthorization::class)->allows($this->personContext($org, $actor), 'organization.view'));
        $this->assertDatabaseCount('person_departures', 1);
    }

    public function test_suspended_and_unverified_subject_can_be_cleaned_up_without_touching_global_account(): void
    {
        [$org,$actor,$person,$subject] = $this->editor();
        $subject->forceFill(['email_verified_at' => null])->save();
        OrganizationMembership::query()->where('organization_id', $org->id)->where('user_id', $subject->id)->update(['status' => 'suspended']);
        app(PersonDepartureService::class)->depart($this->personContext($org, $actor), $person->id, new PersonLifecycleData(['reason_key' => 'decision', 'confirm' => true], $person->version));
        $this->assertDatabaseHas('users', ['id' => $subject->id, 'email_verified_at' => null]);
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $org->id, 'user_id' => $subject->id, 'status' => 'revoked']);
    }

    public function test_owner_cannot_depart_until_property_transfer(): void
    {
        [$org,$actor,$person] = $this->editor();
        $person->forceFill(['user_id' => $org->owner_user_id])->save();
        try {
            app(PersonDepartureService::class)->depart($this->personContext($org, $actor), $person->id, new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version));
            $this->fail('Owner departure must be rejected.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PERSON_DEPARTURE_OWNER_PROTECTED', $exception->errorCode());
            $this->assertDatabaseCount('person_departures', 0);
        }
    }

    public function test_participant_failure_rolls_back_periods_person_and_membership(): void
    {
        [$org,$actor,$person,$subject] = $this->editor();
        $period = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $participant = new class implements PersonDepartureParticipant
        {
            public function closeForDeparture(TenantContext $context, string $personId, CarbonImmutable $at): void
            {
                throw new \RuntimeException('Simulated participant failure');
            }
        };
        app()->instance('test.departure-participant', $participant);
        app()->tag(['test.departure-participant'], 'people.departure-participants');
        try {
            app(PersonDepartureService::class)->depart($this->personContext($org, $actor), $person->id, new PersonLifecycleData(['reason_key' => 'other', 'confirm' => true], $person->version));
            $this->fail('Failure must roll back.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated participant failure', $exception->getMessage());
        }
        $this->assertDatabaseHas('person_periods', ['id' => $period->id, 'status' => 'active', 'ends_at' => null]);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'active', 'version' => $person->version]);
        $this->assertDatabaseHas('organization_memberships', ['organization_id' => $org->id, 'user_id' => $subject->id, 'status' => 'active']);
        $this->assertDatabaseCount('person_departures', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    /** @return array{Organization,User,Person,User} */
    private function editor(bool $self = false): array
    {
        $org = Organization::factory()->create();
        $actor = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $actor, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage']);
        $subject = $self ? $actor : User::factory()->withMfa()->create();
        if (! $self) {
            $this->grantPeopleAccess($org, $subject, ['sports.read']);
        }
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $subject->id]);

        return [$org, $actor, $person, $subject];
    }
}
