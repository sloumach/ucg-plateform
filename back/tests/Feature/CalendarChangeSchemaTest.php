<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CalendarChangeSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_history_identity_and_successful_delivery_are_immutable_in_sql(): void
    {
        $notice = CalendarChangeRecipient::factory()->create();
        foreach ([fn () => DB::table('calendar_changes')->where('id', $notice->change_id)->update(['reason' => 'Replacement']),
            fn () => DB::table('calendar_changes')->where('id', $notice->change_id)->delete(),
            fn () => DB::table('calendar_change_recipients')->where('id', $notice->id)->update(['person_id' => (string) Str::uuid()]),
            fn () => DB::table('calendar_change_recipients')->where('id', $notice->id)->delete(),
            fn () => DB::table('calendar_change_recipients')->where('id', $notice->id)->update(['delivered_at' => now()])] as $attempt) {
            $this->reject($attempt);
        }
        $notice->forceFill(['delivered_at' => now(), 'run_id' => (string) Str::uuid()])->save();
        $this->reject(fn () => DB::table('calendar_change_recipients')->where('id', $notice->id)->update(['run_id' => (string) Str::uuid()]));
        $this->assertNotNull($notice->refresh()->delivered_at);
    }

    public function test_cross_tenant_and_duplicate_recipients_or_change_versions_are_rejected(): void
    {
        $notice = CalendarChangeRecipient::factory()->create();
        $foreign = CalendarChange::factory()->create();
        $person = Person::factory()->create();
        foreach ([fn () => CalendarChangeRecipient::factory()->create(['organization_id' => $notice->organization_id, 'change_id' => $foreign->id]),
            fn () => CalendarChangeRecipient::factory()->create(['organization_id' => $notice->organization_id, 'change_id' => $notice->change_id, 'person_id' => $person->id]),
            fn () => CalendarChangeRecipient::factory()->create($notice->only(['organization_id', 'change_id', 'person_id'])),
            fn () => CalendarChange::factory()->create($notice->change->only(['organization_id', 'activity_id', 'activity_version']))] as $attempt) {
            $this->reject($attempt);
        }
    }

    public function test_cancellation_requires_archival_and_then_preserves_the_terminal_activity(): void
    {
        $activity = CalendarActivity::factory()->create();
        $this->reject(fn () => DB::table('calendar_activities')->where('id', $activity->id)->update(['cancelled_at' => now()]));
        $activity->forceFill(['cancelled_at' => now(), 'archived_at' => now()])->save();
        $this->reject(fn () => DB::table('calendar_activities')->where('id', $activity->id)->update(['cancelled_at' => null, 'archived_at' => null]));
        $this->assertNotNull($activity->refresh()->cancelled_at);
    }

    public function test_migration_round_trip_inventory_export_and_retained_history_guard(): void
    {
        $migration = require database_path('migrations/2026_10_17_220751_create_calendar_changes_and_recipients.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('calendar_changes'));
        $this->assertFalse(Schema::hasColumn('calendar_activities', 'cancelled_at'));
        $migration->up();
        $notice = CalendarChangeRecipient::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repo = app(PortabilityRepository::class);
        $repo->assertCatalog();
        $rows = iterator_to_array($repo->rows($notice->organization_id, 100));
        $changes = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'calendar_changes'));
        $this->assertCount(1, $changes);
        $this->assertNotSame($notice->change->reason, $changes[0]['data']['reason']);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    private function reject(\Closure $attempt): void
    {
        try {
            DB::transaction(function () use ($attempt): void {
                $attempt();
            });
            $this->fail('SQL must reject invalid or mutable calendar change history.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
