<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CalendarSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalid(): array
    {
        return ['identity' => [['organization_id' => '11111111-1111-4111-8111-111111111111']],
            'backwards' => [['ends_at' => '2000-01-01 00:00:00']], 'shared without consent' => [['visibility' => 'organization']],
            'team without reference' => [['visibility' => 'team']], 'unknown kind' => [['kind' => 'bogus']]];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalid')]
    public function test_database_rejects_invalid_activity_shapes(array $changes): void
    {
        $record = CalendarActivity::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('calendar_activities')->where('id', $record->id)->update($changes));
    }

    public function test_cross_tenant_team_person_and_parent_references_are_rejected(): void
    {
        $record = CalendarActivity::factory()->create();
        $foreign = CalendarActivity::factory()->create();
        $person = Person::factory()->create(['organization_id' => $record->organization_id]);
        foreach ([fn () => DB::table('calendar_activities')->where('id', $record->id)->update(['team_id' => Team::factory()->create()->id]),
            fn () => CalendarParticipant::factory()->create(['organization_id' => $record->organization_id, 'activity_id' => $foreign->id, 'person_id' => $person->id]),
            fn () => CalendarParticipant::factory()->create(['organization_id' => $record->organization_id, 'activity_id' => $record->id, 'person_id' => Person::factory()->create()->id])] as $op) {
            try {
                DB::transaction($op);
                $this->fail('Cross tenant association must fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('calendar_participants', 0);
            }
        }
    }

    public function test_archive_retains_participants_and_disallows_bulk_rewrites_or_deletes(): void
    {
        $participant = CalendarParticipant::factory()->create();
        DB::table('calendar_activities')->where('id', $participant->activity_id)->update(['archived_at' => now()]);
        foreach ([fn () => DB::table('calendar_activities')->where('id', $participant->activity_id)->update(['archived_at' => null]),
            fn () => DB::table('calendar_activities')->where('id', $participant->activity_id)->delete(),
            fn () => DB::table('calendar_participants')->where('id', $participant->id)->delete()] as $op) {
            try {
                DB::transaction($op);
                $this->fail('Archived history must remain.');
            } catch (QueryException) {
                $this->assertModelExists($participant);
            }
        }
    }

    public function test_schema_inventory_and_export_preserve_only_own_encrypted_content(): void
    {
        $record = CalendarActivity::factory()->create(['title' => 'PRIVATE_CALENDAR_TITLE']);
        CalendarActivity::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repo = app(PortabilityRepository::class);
        $repo->assertCatalog();
        $rows = iterator_to_array($repo->rows($record->organization_id, 100));
        $activities = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'calendar_activities'));
        $this->assertCount(1, $activities);
        $this->assertSame($record->id, $activities[0]['data']['id']);
        $this->assertNotSame('PRIVATE_CALENDAR_TITLE', $activities[0]['data']['title']);
    }

    public function test_empty_migration_round_trip_and_nonempty_rollback_guard(): void
    {
        $migration = require database_path('migrations/2026_10_17_094942_create_calendar_activities_and_participants.php');
        $bookings = require database_path('migrations/2026_10_17_112802_create_calendar_resources_reservations_and_conflict_overrides.php');
        $invitations = require database_path('migrations/2026_10_17_153712_create_calendar_invitations_and_deliveries.php');
        $changes = require database_path('migrations/2026_10_17_220751_create_calendar_changes_and_recipients.php');
        $changes->down();
        $invitations->down();
        $bookings->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('calendar_activities'));
        $migration->up();
        $bookings->up();
        $invitations->up();
        $changes->up();
        CalendarActivity::factory()->create();
        try {
            $bookings = require database_path('migrations/2026_10_17_112802_create_calendar_resources_reservations_and_conflict_overrides.php');
            $invitations = require database_path('migrations/2026_10_17_153712_create_calendar_invitations_and_deliveries.php');
            $changes = require database_path('migrations/2026_10_17_220751_create_calendar_changes_and_recipients.php');
            $changes->down();
            $invitations->down();
            $bookings->down();
            $migration->down();
            $this->fail('Rollback must retain business data.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('calendar_activities', 1);
            $this->assertTrue(Schema::hasTable('calendar_participants'));
        }
    }
}
