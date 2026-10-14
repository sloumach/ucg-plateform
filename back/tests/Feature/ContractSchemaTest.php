<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Contracts\Domain\Models\ContractObligation;
use App\Modules\Contracts\Domain\Models\ContractParty;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ContractSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_schema_and_export_inventory_include_all_contract_tables(): void
    {
        $this->assertDatabaseCount('contracts', 0);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    /** @return array<string, array{string}> */
    public static function identities(): array
    {
        return ['id' => ['id'], 'tenant' => ['organization_id'], 'reference' => ['reference'], 'type' => ['type'], 'creator' => ['created_by']];
    }

    #[DataProvider('identities')]
    public function test_sql_cannot_repurpose_or_move_a_contract(string $field): void
    {
        $contract = ContractRecord::factory()->create();
        $value = match ($field) {
            'type' => 'coach', 'reference' => 'other', 'created_by' => 999, default => (string) Str::uuid()
        };
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->update([$field => $value]));
    }

    public function test_sql_rejects_cross_tenant_parties_and_responsible_person(): void
    {
        $contract = ContractRecord::factory()->create();
        $foreign = Person::factory()->create();
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->update(['responsible_person_id' => $foreign->id]), '23503');
        $this->reject(fn () => (new ContractParty)->forceFill(['organization_id' => $contract->organization_id, 'contract_id' => $contract->id,
            'kind' => 'person', 'person_id' => $foreign->id, 'name' => 'Foreign', 'position' => 1])->save(), '23503');
        $this->assertDatabaseCount('contract_parties', 0);
    }

    public function test_sql_rejects_status_jumps_self_approval_and_unsigned_activation(): void
    {
        $contract = ContractRecord::factory()->create(['starts_on' => '2026-01-01']);
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->update(['status' => 'active']));
        DB::table('contracts')->where('id', $contract->id)->update(['status' => 'in_review']);
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->update(['status' => 'approved',
            'approved_by' => $contract->prepared_by, 'approved_at' => now()]));
        $this->assertDatabaseHas('contracts', ['id' => $contract->id, 'status' => 'in_review']);
    }

    public function test_archival_requires_regularized_obligations_and_freezes_all_children(): void
    {
        $contract = ContractRecord::factory()->create();
        $item = new ContractObligation;
        $item->forceFill(['organization_id' => $contract->organization_id, 'contract_id' => $contract->id, 'title' => 'Restitution',
            'position' => 1, 'status' => 'pending', 'version' => (string) Str::uuid()])->save();
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->update(['status' => 'archived']));
        $item->forceFill(['status' => 'waived'])->save();
        $this->reject(fn () => DB::table('contract_obligations')->where('id', $item->id)->delete());
        DB::table('contracts')->where('id', $contract->id)->update(['status' => 'archived']);
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->update(['status' => 'draft']));
        $this->reject(fn () => DB::table('contract_obligations')->where('id', $item->id)->update(['status' => 'pending']));
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->delete());
    }

    public function test_history_is_append_only_and_must_reference_the_current_tenant_version(): void
    {
        $contract = ContractRecord::factory()->create();
        $attributes = ['organization_id' => $contract->organization_id, 'contract_id' => $contract->id, 'from_status' => null,
            'to_status' => 'draft', 'contract_version' => $contract->version, 'actor_user_id' => $contract->created_by, 'occurred_at' => now()];
        $event = new ContractTransition;
        $event->forceFill($attributes)->save();
        $this->reject(fn () => DB::table('contract_transitions')->where('id', $event->id)->delete());
        $this->reject(fn () => DB::table('contract_transitions')->where('id', $event->id)->update(['to_status' => 'signed']));
        $this->reject(fn () => (new ContractTransition)->forceFill([...$attributes, 'contract_version' => (string) Str::uuid()])->save());
        $this->assertDatabaseCount('contract_transitions', 1);
    }

    public function test_exports_are_tenant_only_and_keep_confidential_fields_encrypted(): void
    {
        $contract = ContractRecord::factory()->create(['title' => 'PRIVATE_TITLE']);
        ContractRecord::factory()->create(['title' => 'FOREIGN']);
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($contract->organization_id, 1000));
        $contracts = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'contracts'));
        $this->assertCount(1, $contracts);
        $this->assertSame($contract->id, $contracts[0]['data']['id']);
        $this->assertNotSame('PRIVATE_TITLE', $contracts[0]['data']['title']);
    }

    public function test_empty_migration_round_trip_recreates_all_guards(): void
    {
        $this->assertDatabaseCount('contracts', 0);
        $migration = require database_path('migrations/2026_10_14_184245_create_contract_records_and_lifecycle.php');
        $documents = require database_path('migrations/2026_10_14_193828_create_contract_documents_and_immutable_versions.php');
        $documents->down();
        $migration->down();
        $migration->up();
        $documents->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $contract = ContractRecord::factory()->create();
        $this->reject(fn () => DB::table('contracts')->where('id', $contract->id)->delete());
    }

    public function test_rollback_refuses_data_before_dropping_any_table(): void
    {
        ContractRecord::factory()->create();
        $migration = require database_path('migrations/2026_10_14_184245_create_contract_records_and_lifecycle.php');
        try {
            $migration->down();
            $this->fail('Existing contractual data must be retained.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('cannot discard', $error->getMessage());
            $this->assertDatabaseCount('contracts', 1);
            $this->assertDatabaseCount('contract_parties', 0);
        }
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $pgCode = '23514'): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must reject this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $pgCode : '23000', (string) $error->getCode());
        }
    }
}
