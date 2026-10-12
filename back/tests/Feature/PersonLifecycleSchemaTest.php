<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonLifecycleSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string,array{string,string}> */
    public static function identityChanges(): array
    {
        $data = [];
        foreach (['person_periods', 'person_departure_items', 'person_departures'] as $table) {
            foreach (['id', 'organization_id', 'person_id'] as $column) {
                $data[$table.'-'.$column] = [$table, $column];
            }
        }

        return $data;
    }

    #[DataProvider('identityChanges')]
    public function test_sql_rejects_identity_changes(string $table, string $column): void
    {
        $record = match ($table) {
            'person_periods' => PersonPeriod::factory()->create(),
            'person_departure_items' => PersonDepartureItem::factory()->create(),
            default => PersonDeparture::factory()->create(),
        };
        try {
            DB::transaction(fn () => DB::table($table)->where('id', $record->id)->update([$column => (string) Str::uuid()]));
            $this->fail('Identity must be immutable.');
        } catch (QueryException) {
            $this->assertDatabaseHas($table, ['id' => $record->id, 'organization_id' => $record->organization_id, 'person_id' => $record->person_id]);
        }
    }

    /** @return array<string,array{string}> */
    public static function tables(): array
    {
        return ['periods' => ['person_periods'], 'items' => ['person_departure_items'], 'departure' => ['person_departures']];
    }

    #[DataProvider('tables')]
    public function test_sql_rejects_deletion_and_nonempty_rollback(string $table): void
    {
        $record = match ($table) {
            'person_periods' => PersonPeriod::factory()->create(),
            'person_departure_items' => PersonDepartureItem::factory()->create(),
            default => PersonDeparture::factory()->create(),
        };
        try {
            DB::transaction(fn () => DB::table($table)->where('id', $record->id)->delete());
            $this->fail('History must be retained.');
        } catch (QueryException) {
            $this->assertDatabaseCount($table, 1);
        }
        $migration = require database_path('migrations/2026_10_12_173809_create_person_lifecycle_tables.php');
        try {
            $migration->down();
            $this->fail('Populated rollback must be refused.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot discard', $error->getMessage());
            $this->assertDatabaseCount($table, 1);
        }
    }

    #[DataProvider('tables')]
    public function test_composite_fk_rejects_foreign_person(string $table): void
    {
        $person = Person::factory()->create();
        $other = Organization::factory()->create();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => match ($table) {
            'person_periods' => PersonPeriod::factory()->create(['organization_id' => $other->id, 'person_id' => $person->id]),
            'person_departure_items' => PersonDepartureItem::factory()->create(['organization_id' => $other->id, 'person_id' => $person->id]),
            default => PersonDeparture::factory()->create(['organization_id' => $other->id, 'person_id' => $person->id]),
        });
    }

    public function test_empty_rollback_and_reapply_leave_tenant_schema_and_portability_complete(): void
    {
        $migration = require database_path('migrations/2026_10_12_173809_create_person_lifecycle_tables.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('person_periods'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_departed_person_cannot_be_reactivated_or_unlinked_through_sql(): void
    {
        $person = Person::factory()->create(['status' => 'former']);
        PersonDeparture::factory()->create(['organization_id' => $person->organization_id, 'person_id' => $person->id]);
        try {
            DB::transaction(fn () => DB::table('people')->where('id', $person->id)->update(['status' => 'active']));
            $this->fail('Departed status must be frozen.');
        } catch (QueryException) {
            $this->assertDatabaseHas('people', ['id' => $person->id, 'status' => 'former']);
        }
    }

    public function test_sql_rejects_invalid_period_and_completion_state(): void
    {
        $period = PersonPeriod::factory()->create();
        try {
            DB::transaction(fn () => DB::table('person_periods')->where('id', $period->id)->update(['ends_at' => $period->starts_at->subHour()]));
            $this->fail('Invalid period must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas('person_periods', ['id' => $period->id, 'ends_at' => null]);
        }
        $item = PersonDepartureItem::factory()->create();
        try {
            DB::transaction(fn () => DB::table('person_departure_items')->where('id', $item->id)->update(['status' => 'resolved']));
            $this->fail('Missing completion date must be rejected.');
        } catch (QueryException) {
            $this->assertDatabaseHas('person_departure_items', ['id' => $item->id, 'status' => 'open']);
        }
    }

    public function test_operator_backup_is_tenant_scoped_and_keeps_ciphertext(): void
    {
        $item = PersonDepartureItem::factory()->create(['title' => 'SECRET_SERIAL']);
        PersonDepartureItem::factory()->create(['title' => 'OTHER_SECRET']);
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($item->organization_id, 1000));
        $items = array_values(array_filter($rows, fn ($row) => $row['table'] === 'person_departure_items'));
        $this->assertCount(1, $items);
        $this->assertSame($item->id, $items[0]['data']['id']);
        $this->assertNotSame('SECRET_SERIAL', $items[0]['data']['title']);
        $this->assertStringNotContainsString('OTHER_SECRET',json_encode($rows,JSON_THROW_ON_ERROR));
    }
}
