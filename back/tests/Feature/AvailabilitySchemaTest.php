<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\AvailabilityRevision;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AvailabilitySchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalid(): array
    {
        return ['person identity' => [['person_id' => '11111111-1111-4111-8111-111111111111']],
            'backwards' => [['ends_at' => '2000-01-01 00:00:00']], 'too long' => [['ends_at' => '2026-12-01 00:00:00']],
            'unknown status' => [['status' => 'medical']], 'number' => [['number' => 0]]];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalid')]
    public function test_invalid_current_state_cannot_be_written_through_raw_queries(array $changes): void
    {
        $record = Availability::factory()->create();
        $changes = ['number' => 2, 'version' => (string) Str::uuid(), ...$changes];
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('availabilities')->where('id', $record->id)->update($changes));
    }

    public function test_unversioned_raw_update_is_rejected(): void
    {
        $record = Availability::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('availabilities')->where('id', $record->id)->update(['status' => 'uncertain']));
    }

    public function test_composite_foreign_keys_reject_foreign_person_and_foreign_history_parent(): void
    {
        $record = Availability::factory()->create();
        $other = Availability::factory()->create();
        foreach ([
            fn () => Availability::factory()->create(['organization_id' => $record->organization_id, 'person_id' => Person::factory()->create()->id]),
            fn () => AvailabilityRevision::factory()->create(['availability_id' => $other->id, 'organization_id' => $record->organization_id, 'person_id' => $record->person_id]),
        ] as $op) {
            try {
                DB::transaction($op);
                $this->fail('Cross tenant relation must fail.');
            } catch (QueryException) {
                $this->assertDatabaseCount('availability_revisions', 0);
            }
        }
    }

    public function test_history_and_cancelled_slots_cannot_be_rewritten_or_deleted(): void
    {
        $revision = AvailabilityRevision::factory()->create();
        DB::table('availabilities')->where('id', $revision->availability_id)->update(['cancelled_at' => now(), 'number' => 2, 'version' => (string) Str::uuid()]);
        foreach ([
            fn () => DB::table('availabilities')->where('id', $revision->availability_id)->delete(),
            fn () => DB::table('availabilities')->where('id', $revision->availability_id)->update(['cancelled_at' => null, 'number' => 3, 'version' => (string) Str::uuid()]),
            fn () => DB::table('availability_revisions')->where('id', $revision->id)->update(['comment' => 'forged']),
            fn () => DB::table('availability_revisions')->where('id', $revision->id)->delete(),
        ] as $op) {
            try {
                DB::transaction($op);
                $this->fail('History must remain immutable.');
            } catch (QueryException) {
                $this->assertModelExists($revision);
            }
        }
    }

    public function test_inventory_and_operator_export_include_only_tenant_ciphertext(): void
    {
        $record = AvailabilityRevision::factory()->create(['comment' => 'PRIVATE_HISTORY']);
        Availability::factory()->create();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $repo = app(PortabilityRepository::class);
        $repo->assertCatalog();
        $rows = iterator_to_array($repo->rows($record->organization_id, 100));
        $history = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'availability_revisions'));
        $this->assertCount(1, $history);
        $this->assertSame($record->id, $history[0]['data']['id']);
        $this->assertNotSame('PRIVATE_HISTORY', $history[0]['data']['comment']);
    }

    public function test_empty_round_trip_and_rollback_refuses_current_or_historical_data(): void
    {
        $migration = require database_path('migrations/2026_10_17_105547_create_availabilities_and_immutable_revisions.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('availabilities'));
        $migration->up();
        AvailabilityRevision::factory()->create();
        try {
            $migration->down();
            $this->fail('Rollback must retain data.');
        } catch (\RuntimeException) {
            $this->assertDatabaseCount('availability_revisions', 1);
            $this->assertTrue(Schema::hasTable('availabilities'));
        }
    }
}
