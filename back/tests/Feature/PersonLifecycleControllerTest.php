<?php

namespace Tests\Feature;

use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class PersonLifecycleControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess;

    /** @return array<string,array{string,string,int}> */
    public static function limits(): array
    {
        return ['periods' => ['person_periods', 'periods', 1000], 'items' => ['person_departure_items', 'departure-items', 200]];
    }

    #[DataProvider('limits')]
    public function test_record_limits_are_tenant_and_person_scoped(string $table, string $suffix, int $maximum): void
    {
        [$org,$person] = $this->editor();
        $record = $table === 'person_periods'
            ? PersonPeriod::factory()->make(['organization_id' => $org->id, 'person_id' => $person->id])
            : PersonDepartureItem::factory()->make(['organization_id' => $org->id, 'person_id' => $person->id]);
        $base = $record->getAttributes();
        $rows = [];
        for ($i = 0; $i < $maximum; $i++) {
            $rows[] = [...$base, 'id' => (string) Str::uuid()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
        $payload = $table === 'person_periods' ? $this->period() : ['kind' => 'equipment', 'title' => 'New equipment'];
        $this->postJson($this->path($org, $person, $suffix), $payload)->assertConflict();
        $this->assertDatabaseCount($table, $maximum);
        $otherPerson = Person::factory()->create(['organization_id' => $org->id]);
        $this->postJson($this->path($org, $otherPerson, $suffix), $payload)->assertCreated();
    }

    public function test_page_projection_query_count_is_constant_for_one_or_twenty_one_records(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database']);
        [$org,$person] = $this->editor();
        PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $path = $this->path($org, $person, 'periods').'?per_page=21';
        $this->getJson($path)->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(1, 'data');
        $small = count(DB::getQueryLog());
        PersonPeriod::factory()->count(20)->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        DB::flushQueryLog();
        $this->getJson($path)->assertOk()->assertJsonCount(21, 'data');
        $this->assertSame($small, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_departure_freezes_admission_and_game_mutations_but_allows_archival(): void
    {
        [$org,$person,$user] = $this->editor();
        $profile = GameProfile::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $account = ExternalGameAccount::factory()->create(['game_profile_id' => $profile->id]);
        $this->postJson($this->path($org, $person, 'departure'), ['version' => $person->version, 'reason_key' => 'other', 'confirm' => true])->assertOk();
        $version = $person->refresh()->version;
        $base = '/api/v1/tenants/'.$org->id.'/people/'.$person->id;
        $this->patchJson($base, ['version' => $version, 'status' => 'active'])->assertConflict()->assertJsonPath('code', 'PERSON_ALREADY_DEPARTED');
        $this->patchJson($base, ['version' => $version, 'user_id' => $user->id])->assertConflict();
        $this->postJson($base.'/game-profiles', ['game_key' => 'valorant', 'platform_key' => 'pc', 'region_key' => 'eu'])->assertConflict();
        $this->patchJson($base.'/game-profiles/'.$profile->id, ['version' => $profile->version, 'rank' => 'New rank'])->assertConflict();
        $this->patchJson($base.'/game-profiles/'.$profile->id, ['version' => $profile->version, 'status' => 'archived'])->assertOk();
        $accountPath = $base.'/game-profiles/'.$profile->id.'/external-accounts';
        $this->postJson($accountPath, ['provider_key' => 'riot', 'display_name' => 'New'])->assertConflict();
        $this->patchJson($accountPath.'/'.$account->id, ['version' => $account->version, 'status' => 'archived'])->assertOk();
        $this->postJson($this->path($org, $person, 'periods'), $this->period())->assertConflict();
    }

    public function test_departure_requires_business_rights_session_mfa_confirmation_and_current_version(): void
    {
        [$org,$person,$actor] = $this->editor(['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $body = ['version' => $person->version, 'reason_key' => 'other', 'confirm' => true];
        $this->postJson($this->path($org, $person, 'departure'), $body)->assertForbidden();
        [$org,$person,$actor] = $this->editor();
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($this->path($org, $person, 'departure'), [...$body, 'version' => $person->version])->assertForbidden();
        $this->actingAsWithMfa($actor);
        $this->postJson($this->path($org, $person, 'departure'), [...$body, 'version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'PERSON_VERSION_STALE');
        $this->assertDatabaseCount('person_departures', 0);
    }

    public function test_self_departure_returns_action_metadata_without_rolling_back_on_response_authorization(): void
    {
        [$org,$person,$actor] = $this->editor();
        $person->forceFill(['user_id' => $actor->id])->save();
        $this->postJson($this->path($org, $person, 'departure'), ['version' => $person->version, 'reason_key' => 'resignation', 'confirm' => true])->assertOk()->assertJsonPath('data.departed', true);
        $this->getJson($this->path($org, $person, 'periods'))->assertNotFound();
        $this->assertDatabaseCount('person_departures', 1);
    }

    public function test_guest_and_owner_without_business_permissions_cannot_write_periods(): void
    {
        $org = Organization::factory()->withMfaOwner()->create();
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $path = $this->path($org, $person, 'periods');
        $this->postJson($path, $this->period())->assertUnauthorized();
        $this->actingAsWithMfa(User::findOrFail($org->owner_user_id));
        $this->postJson($path, $this->period())->assertForbidden();
        $this->assertDatabaseCount('person_periods', 0);
    }

    public function test_assignment_and_unavailability_have_half_open_effective_periods_and_private_free_audit(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-12T12:00:00+00:00'));
        [$org,$person] = $this->editor();
        $path = $this->path($org, $person, 'periods');
        $first = $this->postJson($path, $this->period())->assertCreated()->assertJsonPath('data.effective', true);
        $this->postJson($path, ['kind' => 'unavailability', 'starts_at' => '2026-10-12T12:00:00+00:00', 'ends_at' => '2026-10-12T13:00:00+00:00'])->assertCreated();
        $this->getJson($path.'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.unavailable_now', true);
        $this->travelTo(new \DateTimeImmutable('2026-10-12T13:00:00+00:00'));
        $this->getJson($path)->assertOk()->assertJsonPath('meta.unavailable_now', false);
        $this->patchJson($path.'/'.$first->json('data.id'), ['version' => $first->json('data.version'), 'status' => 'cancelled'])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseCount('person_periods', 2);
        $this->assertDatabaseCount('organization_audit_events', 3);
    }

    public function test_overlap_rejects_the_same_role_but_allows_adjacent_periods_and_other_roles(): void
    {
        [$org,$person] = $this->editor();
        $path = $this->path($org, $person, 'periods');
        $this->postJson($path, $this->period())->assertCreated();
        $this->postJson($path, $this->period())->assertConflict()->assertJsonPath('code', 'PERSON_PERIOD_OVERLAP');
        $this->postJson($path, [...$this->period(), 'role_key' => 'coach'])->assertCreated();
        $this->postJson($path, [...$this->period(), 'starts_at' => '2026-10-13T12:00:00+00:00', 'ends_at' => null])->assertCreated();
        $this->assertDatabaseCount('person_periods', 3);
    }

    public function test_partial_patch_preserves_start_and_cancellation_is_terminal(): void
    {
        [$org,$person] = $this->editor();
        $record = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $path = $this->path($org, $person, 'periods').'/'.$record->id;
        $start = $record->starts_at->toIso8601String();
        $response = $this->patchJson($path, ['version' => $record->version, 'ends_at' => now()->addDay()->toIso8601String()])->assertOk()->assertJsonPath('data.starts_at', $start);
        $this->patchJson($path, ['version' => $record->version, 'status' => 'cancelled'])->assertConflict()->assertJsonPath('code', 'PERSON_PERIOD_VERSION_STALE');
        $cancelled = $this->patchJson($path, ['version' => $response->json('data.version'), 'status' => 'cancelled'])->assertOk();
        $this->patchJson($path, ['version' => $cancelled->json('data.version'), 'status' => 'active'])->assertConflict();
    }

    public function test_checklist_is_encrypted_and_stays_regulatable_after_departure(): void
    {
        [$org,$person] = $this->editor();
        $path = $this->path($org, $person, 'departure-items');
        $response = $this->postJson($path, ['kind' => 'equipment', 'title' => 'SECRET_SERIAL', 'notes' => 'SECRET_NOTE'])->assertCreated()->assertJsonPath('data.title', 'SECRET_SERIAL');
        $row = DB::table('person_departure_items')->first();
        $this->assertNotNull($row);
        $this->assertNotSame('SECRET_SERIAL', $row->title);
        $this->assertStringNotContainsString('SECRET_', json_encode(DB::table('organization_audit_events')->get(), JSON_THROW_ON_ERROR));
        $departPath = $this->path($org, $person, 'departure');
        $body = ['version' => $person->version, 'reason_key' => 'other', 'confirm' => true];
        $this->postJson($departPath, $body)->assertConflict()->assertJsonPath('code', 'PERSON_DEPARTURE_PENDING_ITEMS');
        $this->postJson($departPath, [...$body, 'acknowledge_pending' => true])->assertOk()->assertJsonPath('data.departed', true);
        $this->patchJson($path.'/'.$response->json('data.id'), ['version' => $response->json('data.version'), 'status' => 'resolved'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->getJson($path)->assertOk()->assertJsonPath('meta.pending_items_count', 0);
        $this->assertDatabaseCount('person_departures', 1);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'former']);
    }

    public function test_sports_reader_cannot_read_or_infer_the_administrative_checklist(): void
    {
        [$org,$person] = $this->editor(['sports.read']);
        PersonDepartureItem::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id, 'title' => 'SECRET']);
        $this->getJson($this->path($org, $person, 'departure-items'))->assertForbidden();
        $this->getJson($this->path($org, $person, 'departure'))->assertOk()->assertJsonMissingPath('data.pending_items_count')->assertJsonMissingPath('data.reason_key');
    }

    #[Group('tenant-isolation')]
    public function test_foreign_parent_and_record_are_404_even_for_a_multi_tenant_actor(): void
    {
        [$org,$person,$user] = $this->editor();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $user, ['sports.read', 'sports.write', 'administrative.read', 'administrative.write']);
        $foreignPerson = Person::factory()->create(['organization_id' => $other->id]);
        $foreign = PersonPeriod::factory()->create(['organization_id' => $other->id, 'person_id' => $foreignPerson->id]);
        $item = PersonDepartureItem::factory()->create(['organization_id' => $other->id, 'person_id' => $foreignPerson->id]);
        $this->getJson($this->path($other, $foreignPerson, 'periods'))->assertOk()->assertJsonPath('data.0.id', $foreign->id);
        $this->getJson($this->path($other, $foreignPerson, 'departure-items'))->assertOk()->assertJsonPath('data.0.id', $item->id);
        $this->getJson($this->path($org, Person::query()->findOrFail($foreign->person_id), 'periods'))->assertNotFound();
        $this->getJson($this->path($org, $foreignPerson, 'departure-items'))->assertNotFound();
        $this->postJson($this->path($org, $foreignPerson, 'periods'), $this->period())->assertNotFound();
        $this->postJson($this->path($org, $foreignPerson, 'departure-items'), ['kind' => 'equipment', 'title' => 'New'])->assertNotFound();
        $this->patchJson($this->path($org, $person, 'periods').'/'.$foreign->id, ['version' => $foreign->version, 'status' => 'cancelled'])->assertNotFound();
        $this->patchJson($this->path($org, $person, 'departure-items').'/'.$item->id, ['version' => $item->version, 'status' => 'resolved'])->assertNotFound();
        $this->assertDatabaseHas('person_periods', ['id' => $foreign->id, 'status' => 'active', 'version' => $foreign->version]);
        $this->assertDatabaseHas('person_departure_items', ['id' => $item->id, 'status' => 'open', 'version' => $item->version]);
        $this->assertDatabaseCount('person_periods', 1);
        $this->assertDatabaseCount('person_departure_items', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    public function test_same_tenant_period_and_checklist_ids_return_404_under_another_person(): void
    {
        [$org, $person] = $this->editor();
        $other = Person::factory()->create(['organization_id' => $org->id]);
        $period = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $other->id]);
        $item = PersonDepartureItem::factory()->create(['organization_id' => $org->id, 'person_id' => $other->id]);
        $this->getJson($this->path($org, $other, 'periods'))->assertOk()->assertJsonPath('data.0.id', $period->id);
        $this->getJson($this->path($org, $other, 'departure-items'))->assertOk()->assertJsonPath('data.0.id', $item->id);

        $this->getJson($this->path($org, $person, 'periods'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path($org, $person, 'departure-items'))->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson($this->path($org, $person, 'periods').'/'.$period->id, ['version' => $period->version, 'status' => 'cancelled'])->assertNotFound();
        $this->patchJson($this->path($org, $person, 'departure-items').'/'.$item->id, ['version' => $item->version, 'status' => 'resolved'])->assertNotFound();

        $this->assertDatabaseHas('person_periods', ['id' => $period->id, 'status' => 'active', 'version' => $period->version]);
        $this->assertDatabaseHas('person_departure_items', ['id' => $item->id, 'status' => 'open', 'version' => $item->version]);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    public function test_revocation_blocks_warmed_periods_and_checklist_without_writes(string $revoked, int $status): void
    {
        [$org, $person, $user] = $this->editor();
        $period = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $item = PersonDepartureItem::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $this->getJson($this->path($org, $person, 'periods'))->assertOk();
        $this->getJson($this->path($org, $person, 'departure-items'))->assertOk();
        $model = $revoked === 'membership' ? OrganizationMembership::class : OrganizationRoleAssignment::class;
        $model::query()->where('organization_id', $org->id)->where('user_id', $user->id)
            ->firstOrFail()->forceFill(['status' => 'revoked'])->save();

        foreach (['periods', 'departure-items', 'departure'] as $suffix) {
            $this->getJson($this->path($org, $person, $suffix))->assertStatus($status)->assertJsonMissingPath('data');
        }
        $this->postJson($this->path($org, $person, 'periods'), $this->period())->assertStatus($status);
        $this->patchJson($this->path($org, $person, 'periods').'/'.$period->id, ['version' => $period->version, 'status' => 'cancelled'])->assertStatus($status);
        $this->postJson($this->path($org, $person, 'departure-items'), ['kind' => 'equipment', 'title' => 'New'])->assertStatus($status);
        $this->patchJson($this->path($org, $person, 'departure-items').'/'.$item->id, ['version' => $item->version, 'status' => 'resolved'])->assertStatus($status);
        $this->postJson($this->path($org, $person, 'departure'), ['version' => $person->version, 'reason_key' => 'other', 'confirm' => true, 'acknowledge_pending' => true])->assertStatus($status);

        $this->assertDatabaseHas('person_periods', ['id' => $period->id, 'status' => 'active', 'version' => $period->version]);
        $this->assertDatabaseHas('person_departure_items', ['id' => $item->id, 'status' => 'open', 'version' => $item->version]);
        $this->assertDatabaseHas('people', ['id' => $person->id, 'version' => $person->version]);
        $this->assertDatabaseCount('person_periods', 1);
        $this->assertDatabaseCount('person_departure_items', 1);
        $this->assertDatabaseCount('person_departures', 0);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    #[Group('tenant-isolation')]
    public function test_removing_write_permissions_preserves_period_and_checklist_reads_without_mutations(): void
    {
        [$org, $person, $user] = $this->editor();
        $period = PersonPeriod::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $item = PersonDepartureItem::factory()->create(['organization_id' => $org->id, 'person_id' => $person->id]);
        $this->getJson($this->path($org, $person, 'periods'))->assertOk();
        $this->getJson($this->path($org, $person, 'departure-items'))->assertOk();
        OrganizationRoleAssignment::query()->where('organization_id', $org->id)->where('user_id', $user->id)
            ->firstOrFail()->role->forceFill(['permissions' => ['sports.read', 'administrative.read']])->save();

        $this->getJson($this->path($org, $person, 'periods'))->assertOk()->assertJsonPath('data.0.id', $period->id);
        $this->getJson($this->path($org, $person, 'departure-items'))->assertOk()->assertJsonPath('data.0.id', $item->id);
        $this->postJson($this->path($org, $person, 'periods'), $this->period())->assertForbidden();
        $this->patchJson($this->path($org, $person, 'periods').'/'.$period->id, ['version' => $period->version, 'status' => 'cancelled'])->assertForbidden();
        $this->postJson($this->path($org, $person, 'departure-items'), ['kind' => 'equipment', 'title' => 'New'])->assertForbidden();
        $this->patchJson($this->path($org, $person, 'departure-items').'/'.$item->id, ['version' => $item->version, 'status' => 'resolved'])->assertForbidden();

        $this->assertDatabaseHas('person_periods', ['id' => $period->id, 'status' => 'active', 'version' => $period->version]);
        $this->assertDatabaseHas('person_departure_items', ['id' => $item->id, 'status' => 'open', 'version' => $item->version]);
        $this->assertDatabaseCount('person_periods', 1);
        $this->assertDatabaseCount('person_departure_items', 1);
        $this->assertDatabaseCount('organization_audit_events', 0);
    }

    /** @return array<string,array{string,array<string,mixed>,string}> */
    public static function invalid(): array
    {
        return [
            'unknown period' => ['periods', ['organization_id' => 'bad'], 'organization_id'],
            'role absent' => ['periods', ['kind' => 'assignment', 'starts_at' => '2026-10-12T12:00:00+00:00'], 'role_key'],
            'unavailability role' => ['periods', ['kind' => 'unavailability', 'role_key' => 'coach', 'starts_at' => '2026-10-12T12:00:00+00:00'], 'role_key'],
            'reversed' => ['periods', ['kind' => 'assignment', 'role_key' => 'player', 'starts_at' => '2026-10-12T12:00:00+00:00', 'ends_at' => '2026-10-12T11:00:00+00:00'], 'ends_at'],
            'invalid offset' => ['periods', ['kind' => 'unavailability', 'starts_at' => '2026-10-12T12:00:00'], 'starts_at'],
            'private title absent' => ['departure-items', ['kind' => 'equipment'], 'title'],
            'private title size' => ['departure-items', ['kind' => 'equipment', 'title' => str_repeat('x', 121)], 'title'],
            'waiver reason' => ['departure-items', ['kind' => 'equipment', 'title' => 'Keyboard', 'status' => 'waived'], 'notes'],
            'server completion' => ['departure-items', ['kind' => 'equipment', 'title' => 'Keyboard', 'completed_at' => null], 'completed_at'],
            'explicit confirm' => ['departure', ['reason_key' => 'other', 'confirm' => false], 'confirm'],
            'future departure' => ['departure', ['reason_key' => 'other', 'confirm' => true, 'departed_at' => '2030-01-01T00:00:00+00:00'], 'departed_at'],
        ];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('invalid')]
    public function test_invalid_payload_returns_422_without_persisting(string $suffix, array $body, string $field): void
    {
        [$org,$person] = $this->editor();
        $body = $suffix === 'departure' ? [...$body, 'version' => $person->version] : $body;
        $this->postJson($this->path($org, $person, $suffix), $body)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('person_periods', 0);
        $this->assertDatabaseCount('person_departure_items', 0);
        $this->assertDatabaseCount('person_departures', 0);
    }

    /** @param list<string> $permissions
     * @return array{Organization,Person,User}
     */
    private function editor(array $permissions = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'memberships.manage', 'invitations.manage']): array
    {
        $org = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);

        return [$org, Person::factory()->create(['organization_id' => $org->id]), $user];
    }

    private function path(Organization $org, Person $person, string $suffix): string
    {
        return '/api/v1/tenants/'.$org->id.'/people/'.$person->id.'/'.$suffix;
    }

    /** @return array<string,mixed> */
    private function period(): array
    {
        return ['kind' => 'assignment', 'role_key' => 'player', 'starts_at' => '2026-10-12T12:00:00+00:00', 'ends_at' => '2026-10-13T12:00:00+00:00'];
    }
}
