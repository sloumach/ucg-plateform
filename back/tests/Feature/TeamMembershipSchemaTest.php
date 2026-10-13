<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TeamMembershipSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_empty_migration_round_trip_and_portability_catalog_remain_valid(): void
    {
        $this->assertDatabaseCount('team_memberships', 0);
        $migration = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $migration->down();
        $migration->up();
        app(PortabilityRepository::class)->assertCatalog();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $this->assertTrue(Schema::hasTable('team_scope_grants'));
    }

    /** @return array<string,array{string}> */
    public static function identities(): array
    {
        return ['person' => ['person_id'], 'team' => ['team_id'], 'organization' => ['organization_id'], 'role' => ['role_key'], 'start' => ['starts_at']];
    }

    #[DataProvider('identities')]
    public function test_sql_cannot_repurpose_or_move_a_membership(string $field): void
    {
        $period = TeamMembership::factory()->create();
        $value = match ($field) {
            'person_id' => Person::factory()->create(['organization_id' => $period->organization_id])->id,'team_id' => Team::factory()->create(['organization_id' => $period->organization_id])->id,
            'role_key' => 'coach','starts_at' => now()->subDays(2)->format('Y-m-d H:i:s'),default => Str::uuid()->toString()
        };
        $this->reject(fn () => DB::table('team_memberships')->where('id', $period->id)->update([$field => $value]));
    }

    public function test_sql_rejects_incompatible_overlap_but_accepts_adjacent_period(): void
    {
        $this->freezeSecond();
        $period = TeamMembership::factory()->create(['ends_at' => now()]);
        $values = $period->getAttributes();
        $values['id'] = Str::uuid()->toString();
        $values['role_key'] = 'substitute';
        $this->reject(fn () => DB::table('team_memberships')->insert($values));
        $values['starts_at'] = now()->format('Y-m-d H:i:s');
        $values['ends_at'] = null;
        DB::table('team_memberships')->insert($values);
        $this->assertDatabaseCount('team_memberships', 2);
    }

    public function test_cross_tenant_foreign_keys_and_archived_contexts_block_insertion(): void
    {
        $period = TeamMembership::factory()->create();
        $values = $period->getAttributes();
        $values['id'] = Str::uuid()->toString();
        $values['role_key'] = 'coach';
        $values['person_id'] = Person::factory()->create()->id;
        $this->reject(fn () => DB::table('team_memberships')->insert($values));
        $values['person_id'] = $period->person_id;
        $team = Team::findOrFail($period->team_id);
        $team->forceFill(['status' => 'archived'])->save();
        $this->reject(fn () => DB::table('team_memberships')->insert($values));
    }

    public function test_closed_periods_cannot_be_deleted_extended_or_reactivated_and_down_preserves_history(): void
    {
        $period = TeamMembership::factory()->create(['status' => 'closed', 'ends_at' => now()]);
        $this->reject(fn () => DB::table('team_memberships')->where('id', $period->id)->delete());
        $this->reject(fn () => DB::table('team_memberships')->where('id', $period->id)->update(['status' => 'active']));
        $this->reject(fn () => DB::table('team_memberships')->where('id', $period->id)->update(['ends_at' => now()->addDay()]));
        $migration = require database_path('migrations/2026_10_13_152226_create_team_membership_and_movement_tables.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_current_period_can_only_be_shortened_and_invalid_dates_never_persist(): void
    {
        $period = TeamMembership::factory()->create(['ends_at' => now()->addDay()]);
        $this->reject(fn () => DB::table('team_memberships')->where('id', $period->id)->update(['ends_at' => null]));
        $this->reject(fn () => DB::table('team_memberships')->where('id', $period->id)->update(['ends_at' => $period->starts_at]));
    }

    public function test_movement_journal_and_grant_binding_are_append_only_and_exports_are_tenant_scoped(): void
    {
        $period = TeamMembership::factory()->create();
        $org = Organization::findOrFail($period->organization_id);
        $eventId = (string) Str::uuid();
        DB::table('team_movements')->insert(['id' => $eventId, 'organization_id' => $org->id, 'team_id' => $period->team_id, 'membership_id' => $period->id,
            'destination_team_id' => null, 'destination_membership_id' => null, 'action' => 'created', 'reason_key' => 'initial', 'actor_user_id' => $org->owner_user_id,
            'effective_at' => $period->starts_at, 'request_id' => (string) Str::uuid(), 'created_at' => now()]);
        $this->reject(fn () => DB::table('team_movements')->where('id', $eventId)->delete());
        $this->reject(fn () => DB::table('team_movements')->where('id', $eventId)->update(['reason_key' => 'other']));
        $role = OrganizationRole::factory()->create(['organization_id' => $org->id]);
        $grant = OrganizationRoleAssignment::factory()->create(['organization_id' => $org->id, 'role_id' => $role->id, 'user_id' => $org->owner_user_id,
            'membership_id' => null, 'scope_type' => 'team', 'scope_id' => $period->team_id]);
        $binding = (string) Str::uuid();
        DB::table('team_scope_grants')->insert(['id' => $binding, 'organization_id' => $org->id, 'role_assignment_id' => $grant->id, 'team_membership_id' => $period->id, 'created_at' => now()]);
        $this->reject(fn () => DB::table('team_scope_grants')->where('id', $binding)->delete());
        $this->reject(fn () => DB::table('team_scope_grants')->where('id', $binding)->update(['team_membership_id' => (string) Str::uuid()]));
        $foreign = TeamMembership::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 1000));
        $memberships = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'team_memberships'));
        $this->assertCount(1, $memberships);
        $this->assertSame($period->id, $memberships[0]['data']['id']);
        $this->assertNotSame($foreign->id, $memberships[0]['data']['id']);
        $this->assertCount(1, array_filter($rows, fn (array $row): bool => $row['table'] === 'team_movements'));
        $this->assertCount(1, array_filter($rows, fn (array $row): bool => $row['table'] === 'team_scope_grants'));
    }

    /** @param \Closure():mixed $write */
    private function reject(\Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL must reject this write.');
        } catch (QueryException $error) {
            $this->assertContains((string) $error->getCode(), ['23000', '23514', '23503']);
        }
    }
}
