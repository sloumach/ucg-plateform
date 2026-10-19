<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCompetitionRegistrations;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CompetitionRegistrationSchemaTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCompetitionRegistrations,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    public function test_empty_schema_roundtrip_and_inventory_are_complete(): void
    {
        $migration = require database_path('migrations/2026_10_18_231153_create_competition_registration_tracking.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('competition_registration_revisions'));
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_sql_prevents_rewriting_deleting_or_skipping_revisions(): void
    {
        $s = $this->registrationScenario(true);
        $row = CompetitionRegistrationRevision::query()->firstOrFail();
        $attributes = $row->getAttributes();
        $this->reject(fn () => DB::table('competition_registration_revisions')->update(['payload' => 'overwrite']));
        $this->reject(fn () => DB::table('competition_registration_revisions')->delete());
        $this->reject(fn () => DB::table('competition_registration_revisions')->insert([...$attributes, 'id' => (string) Str::uuid(), 'number' => 3]));
        $this->reject(fn () => DB::table('competition_registration_revisions')->insert([...$attributes, 'id' => (string) Str::uuid(), 'organization_id' => (string) Str::uuid(), 'number' => 1]));
        $this->reject(fn () => DB::table('competition_registration_revisions')->insert([...$attributes, 'id' => (string) Str::uuid(), 'number' => 1001]));
        $this->assertDatabaseCount('competition_registration_revisions', 1);
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_18_231153_create_competition_registration_tracking.php'))->down();
    }

    public function test_encrypted_exports_are_limited_to_the_requested_tenant(): void
    {
        $s = $this->registrationScenario(true);
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $owned = array_values(array_filter($rows, fn (array $row): bool => $row['table'] === 'competition_registration_revisions'));
        $this->assertCount(1, $owned);
        $serialized = json_encode($owned, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Suivi interne privé', $serialized);
        $this->assertStringNotContainsString('Joueur', $serialized);
    }

    /**
     * @param \Closure():mixed $operation */
    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('SQL must reject the write.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
