<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarConflictOverride;
use App\Modules\Scheduling\Domain\Models\CalendarReservation;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CalendarConflictSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_composite_foreign_keys_reject_cross_tenant_reservations_and_exceptions(): void
    {
        $item = CalendarActivity::factory()->create();
        $foreign = CalendarActivity::factory()->create();
        $resource = CalendarResource::factory()->create();
        foreach ([
            fn () => CalendarReservation::factory()->create(['organization_id' => $item->organization_id, 'activity_id' => $item->id, 'resource_id' => $resource->id]),
            fn () => CalendarReservation::factory()->create(['organization_id' => $item->organization_id, 'activity_id' => $foreign->id]),
            fn () => CalendarConflictOverride::factory()->create(['organization_id' => $item->organization_id, 'activity_id' => $foreign->id]),
        ] as $op) {
            try {
                DB::transaction($op);
                $this->fail('Foreign tenant relation must fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('calendar_reservations', 0);
                $this->assertDatabaseCount('calendar_conflict_overrides', 0);
            }
        }
    }

    public function test_archived_activity_reservations_and_resource_identities_are_protected(): void
    {
        $reservation = CalendarReservation::factory()->create();
        DB::table('calendar_activities')->where('id', $reservation->activity_id)->update(['archived_at' => now()]);
        foreach ([
            fn () => DB::table('calendar_reservations')->where('id', $reservation->id)->delete(),
            fn () => DB::table('calendar_reservations')->where('id', $reservation->id)->update(['resource_id' => $reservation->resource_id]),
            fn () => DB::table('calendar_resources')->where('id', $reservation->resource_id)->update(['key' => 'new-key']),
            fn () => DB::table('calendar_resources')->where('id', $reservation->resource_id)->delete(),
        ] as $op) {
            try {
                DB::transaction($op);
                $this->fail('Retained booking must remain.');
            } catch (QueryException) {
                $this->assertModelExists($reservation);
            }
        }
    }

    public function test_exception_is_append_only_and_retains_encrypted_snapshot(): void
    {
        $entry = CalendarConflictOverride::factory()->create(['reason' => 'SECRET_REASON', 'snapshot' => ['conflicts' => [['title' => 'SECRET_TITLE']]]]);
        foreach (['reason', 'snapshot'] as $field) {
            $this->assertStringNotContainsString('SECRET_', DB::table('calendar_conflict_overrides')->value($field));
        }
        foreach ([fn () => DB::table('calendar_conflict_overrides')->where('id', $entry->id)->update(['reason' => 'No']), fn () => DB::table('calendar_conflict_overrides')->where('id', $entry->id)->delete()] as $op) {
            try {
                DB::transaction($op);
                $this->fail('Exception history is immutable.');
            } catch (QueryException) {
                $this->assertModelExists($entry);
            }
        }
    }

    public function test_schema_inventory_and_export_keep_only_own_ciphertext(): void
    {
        $entry = CalendarConflictOverride::factory()->create();
        CalendarResource::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repo = app(PortabilityRepository::class);
        $repo->assertCatalog();
        $rows = iterator_to_array($repo->rows($entry->organization_id, 100));
        $history = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'calendar_conflict_overrides'));
        $this->assertCount(1, $history);
        $this->assertSame($entry->id, $history[0]['data']['id']);
        $this->assertNotSame($entry->reason, $history[0]['data']['reason']);
    }

    public function test_empty_round_trip_and_nonempty_rollback_guard(): void
    {
        $migration = require database_path('migrations/2026_10_17_112802_create_calendar_resources_reservations_and_conflict_overrides.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('calendar_resources'));
        $migration->up();
        CalendarResource::factory()->create();
        try {
            $migration->down();
            $this->fail('Existing resources must survive.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('calendar_resources', 1);
            $this->assertTrue(Schema::hasTable('calendar_reservations'));
        }
    }
}
