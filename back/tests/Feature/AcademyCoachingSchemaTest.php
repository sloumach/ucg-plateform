<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesAcademyCoaching;
use Tests\Fixtures\ProvidesAcademySessions;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class AcademyCoachingSchemaTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesAcademyCoaching, ProvidesAcademySessions, ProvidesPeopleAccess;

    public function test_empty_round_trip_restores_tenant_schema_and_portability_catalogue(): void
    {
        $this->assertDatabaseCount('academy_objectives', 0);
        $migration = require database_path('migrations/2026_10_22_224821_create_academy_objectives_and_feedback.php');
        $migration->down();
        $this->assertFalse(DB::getSchemaBuilder()->hasTable('academy_objectives'));
        $migration->up();
        foreach (['academy_objectives', 'academy_feedback', 'academy_coaching_revisions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
    }

    public function test_used_migration_refuses_to_discard_objectives(): void
    {
        $s = $this->coachingScenario();
        $this->coachingObjective($s);
        $migration = require database_path('migrations/2026_10_22_224821_create_academy_objectives_and_feedback.php');
        try {
            $migration->down();
            $this->fail('Used coaching history must remain.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('cannot discard', $error->getMessage());
        }
        $this->assertDatabaseCount('academy_objectives', 1);
    }

    public function test_sql_cannot_widen_objective_audience_or_reclassify_internal_feedback(): void
    {
        $s = $this->coachingScenario();
        $objective = $this->coachingObjective($s);
        $note = $this->postJson($s['path'].'/'.$objective->id.'/notes', ['content' => 'Note privée', 'justification' => 'Préparer une discussion privée.'])->assertCreated();
        foreach ([fn () => DB::table('academy_objectives')->where('id', $objective->id)->update(['target_enrollment_id' => null]),
            fn () => DB::table('academy_feedback')->where('id', $note->json('data.id'))->update(['audience' => 'shared', 'justification' => null]),
            fn () => DB::table('academy_coaching_revisions')->delete(), fn () => DB::table('academy_feedback')->delete(),
            fn () => DB::table('academy_objectives')->delete()] as $write) {
            try {
                DB::transaction($write);
                $this->fail('SQL must preserve audience and history.');
            } catch (QueryException $error) {
                $this->assertSame(DB::getDriverName() === 'pgsql' ? '23514' : '23000', (string) $error->getCode());
            }
        }
        $this->assertDatabaseCount('academy_feedback', 1);
        $this->assertDatabaseCount('academy_coaching_revisions', 1);
    }
}
