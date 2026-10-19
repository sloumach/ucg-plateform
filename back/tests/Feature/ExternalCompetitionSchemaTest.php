<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Domain\Models\ExternalCompetitionDocument;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesExternalCompetitions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ExternalCompetitionSchemaTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesExternalCompetitions,ProvidesPeopleAccess;

    public function test_empty_roundtrip_keeps_the_tenant_inventory_complete(): void
    {
        $migration = require database_path('migrations/2026_10_18_210127_create_external_competitions_and_private_documents.php');
        $registrations = require database_path('migrations/2026_10_18_231153_create_competition_registration_tracking.php');
        $registrations->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('external_competitions'));
        $migration->up();
        $registrations->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_database_protects_identity_history_and_parent_updates(): void
    {
        $s = $this->competitionScenario();
        foreach (['version' => (string) Str::uuid(), 'name' => 'replace', 'organization_id' => (string) Str::uuid(), 'reference' => 'REPLACE', 'status' => 'archived'] as $field => $value) {
            $this->reject(fn () => DB::table('external_competitions')->where('id', $s['record']['id'])->update([$field => $value]));
        }
        $this->reject(fn () => DB::table('external_competition_revisions')->update(['reason' => 'overwrite']));
        $this->reject(fn () => DB::table('external_competition_revisions')->delete());
        $this->reject(fn () => DB::table('external_competitions')->delete());
        $this->assertDatabaseCount('external_competition_revisions', 1);
        $migration = require database_path('migrations/2026_10_18_210127_create_external_competitions_and_private_documents.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_document_revisions_are_immutable_and_cannot_move_or_change_kind(): void
    {
        $s = $this->competitionScenario();
        $doc = $this->postJson($s['root'].'/'.$s['record']['id'].'/documents', $this->competitionDocument($s))->assertCreated()->json('data');
        $model = ExternalCompetitionDocument::query()->whereKey($doc['version'])->firstOrFail();
        $attrs = $model->getAttributes();
        $this->reject(fn () => DB::table('external_competition_documents')->update(['body' => 'overwrite']));
        $this->reject(fn () => DB::table('external_competition_documents')->delete());
        $foreign = ExternalCompetition::factory()->create();
        foreach ([['organization_id' => $foreign->organization_id], ['competition_id' => $foreign->id], ['number' => 4], ['number' => 2, 'kind' => 'note'], ['document_id' => (string) Str::uuid(), 'number' => 2]] as $invalid) {
            $this->reject(fn () => DB::table('external_competition_documents')->insert([...$attrs, 'id' => (string) Str::uuid(), ...$invalid]));
        }
        $this->assertDatabaseCount('external_competition_documents', 1);
    }

    public function test_tenant_exports_include_encrypted_rows_without_foreign_contacts(): void
    {
        $s = $this->competitionScenario();
        $this->postJson($s['root'].'/'.$s['record']['id'].'/documents', $this->competitionDocument($s))->assertCreated();
        $foreign = ExternalCompetition::factory()->create(['contacts' => [['name' => 'Foreign contact', 'role' => null, 'email' => 'foreign@example.test', 'phone' => null]]]);
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 100));
        $owned = array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['table'], 'external_competition')));
        $this->assertCount(3, $owned);
        $serialized = json_encode($owned, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('foreign@example.test', $serialized);
        $this->assertStringNotContainsString('private@example.test', $serialized);
        $this->assertStringNotContainsString('texte conservé', $serialized);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_document_revision_limit_is_enforced_without_overwriting_the_last_version(): void
    {
        $s = $this->competitionScenario();
        $path = $s['root'].'/'.$s['record']['id'].'/documents';
        $doc = $this->postJson($path, $this->competitionDocument($s))->assertCreated()->json('data');
        $attributes = DB::table('external_competition_documents')->where('id', $doc['version'])->first();
        $this->assertNotNull($attributes);
        $last = $doc['version'];
        for ($number = 2; $number <= 100; $number++) {
            $last = (string) Str::uuid();
            DB::table('external_competition_documents')->insert([...(array) $attributes, 'id' => $last, 'number' => $number]);
        }
        $this->patchJson($path.'/'.$doc['document_id'], ['competition_version' => $s['record']['version'], 'version' => $last, 'body' => 'Version excédentaire interdite.', 'reason' => 'La limite de conservation est déjà atteinte.'])->assertConflict()->assertJsonPath('code', 'EXTERNAL_COMPETITION_DOCUMENT_LIMIT');
        $this->assertDatabaseCount('external_competition_documents', 100);
        $row = DB::table('external_competitions')->where('id', $s['record']['id'])->first();
        $this->assertNotNull($row);
        $this->reject(fn () => DB::table('external_competitions')->insert([...(array) $row, 'id' => (string) Str::uuid(), 'reference' => 'NULL_TIME', 'created_at' => null]));
    }

    private function reject(\Closure $operation): void
    {
        try {
            DB::transaction(fn () => $operation());
            $this->fail('The SQL guard must reject this mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
