<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PartnershipSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tenant_schema_and_complete_export_catalog_include_partnerships(): void
    {
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        foreach (['sponsors', 'sponsor_contacts', 'sponsorship_opportunities', 'sponsorship_interactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_sql_cannot_move_sponsors_or_children_or_delete_history(): void
    {
        $sponsor = Sponsor::factory()->create();
        $other = Sponsor::factory()->create(['organization_id' => $sponsor->organization_id]);
        $contact = $this->contact($sponsor);
        $this->reject(fn () => DB::table('sponsors')->where('id', $sponsor->id)->update(['organization_id' => Organization::factory()->create()->id]));
        $this->reject(fn () => DB::table('sponsor_contacts')->where('id', $contact)->update(['sponsor_id' => $other->id]));
        $this->reject(fn () => DB::table('sponsor_contacts')->where('id', $contact)->delete());
        $this->reject(fn () => DB::table('sponsors')->where('id', $sponsor->id)->delete());
        $this->assertDatabaseHas('sponsor_contacts', ['id' => $contact, 'sponsor_id' => $sponsor->id]);
    }

    public function test_composite_foreign_keys_reject_foreign_sponsors_and_foreign_contacts(): void
    {
        $sponsor = Sponsor::factory()->create();
        $other = Sponsor::factory()->create(['organization_id' => $sponsor->organization_id]);
        $foreign = Sponsor::factory()->create();
        $contact = $this->contact($other);
        $this->reject(fn () => DB::table('sponsorship_interactions')->insert($this->exchange($sponsor, $contact)), '23503');
        $this->reject(fn () => DB::table('sponsor_contacts')->insert(['id' => (string) Str::uuid(), 'organization_id' => $sponsor->organization_id,
            'sponsor_id' => $foreign->id, 'name' => 'Contact', 'status' => 'active', 'version' => (string) Str::uuid()]));
    }

    public function test_sql_archives_are_immutable_and_block_new_children(): void
    {
        $sponsor = Sponsor::factory()->create();
        $contact = $this->contact($sponsor);
        DB::table('sponsor_contacts')->where('id', $contact)->update(['status' => 'archived']);
        $this->reject(fn () => DB::table('sponsor_contacts')->where('id', $contact)->update(['status' => 'active']));
        DB::table('sponsors')->where('id', $sponsor->id)->update(['status' => 'archived']);
        $this->reject(fn () => DB::table('sponsors')->where('id', $sponsor->id)->update(['name' => 'New']));
        $this->reject(fn () => $this->contact($sponsor));
        $this->reject(fn () => DB::table('sponsorship_interactions')->insert($this->exchange($sponsor)));
    }

    public function test_manual_interaction_is_append_only_even_through_bulk_sql(): void
    {
        $sponsor = Sponsor::factory()->create();
        $row = $this->exchange($sponsor);
        DB::table('sponsorship_interactions')->insert($row);
        $this->reject(fn () => DB::table('sponsorship_interactions')->where('id', $row['id'])->update(['summary' => 'Changed']));
        $this->reject(fn () => DB::table('sponsorship_interactions')->where('id', $row['id'])->delete());
        $this->assertDatabaseHas('sponsorship_interactions', ['id' => $row['id'], 'summary' => 'Conversation commerciale']);
    }

    public function test_rollback_refuses_to_discard_used_records(): void
    {
        Sponsor::factory()->create();
        $migration = require database_path('migrations/2026_10_15_211008_create_partnership_prospects_and_contacts.php');
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_blank_names_are_rejected_by_sql(): void
    {
        $sponsor = Sponsor::factory()->create();
        $this->reject(fn () => DB::table('sponsors')->where('id', $sponsor->id)->update(['name' => '  ']));
    }

    private function contact(Sponsor $sponsor): string
    {
        $id = (string) Str::uuid();
        DB::table('sponsor_contacts')->insert(['id' => $id, 'organization_id' => $sponsor->organization_id, 'sponsor_id' => $sponsor->id,
            'name' => 'Contact', 'status' => 'active', 'version' => (string) Str::uuid()]);

        return $id;
    }

    /** @return array<string, mixed> */
    private function exchange(Sponsor $sponsor, ?string $contactId = null): array
    {
        return ['id' => (string) Str::uuid(), 'organization_id' => $sponsor->organization_id, 'sponsor_id' => $sponsor->id,
            'contact_id' => $contactId, 'channel' => 'meeting', 'summary' => 'Conversation commerciale', 'occurred_at' => now(),
            'created_by' => User::factory()->create()->id, 'created_at' => now()];
    }

    /** @param Closure(): mixed $write */
    private function reject(Closure $write, string $postgresCode = '23514'): void
    {
        try {
            DB::transaction($write);
            $this->fail('The database must refuse this write.');
        } catch (QueryException $error) {
            $this->assertSame(DB::getDriverName() === 'pgsql' ? $postgresCode : '23000', (string) $error->getCode());
        }
    }
}
