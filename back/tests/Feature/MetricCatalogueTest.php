<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Application\Services\PlatformRoleService;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Identity\Domain\PlatformRoleType;
use App\Modules\Performance\Application\Services\MetricCatalogueService;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\TestCase;

class MetricCatalogueTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMetricCatalogue;

    public function test_global_definition_is_readable_without_tenant_values_and_has_an_exact_historical_version(): void
    {
        $s = $this->metricScenario(true);
        $first = $s['record'];
        $response = $this->getJson($s['root'])->assertOk();
        $this->assertSame($response->headers->get('X-Request-ID'), $response->json('meta.request_id'));
        $this->assertSame(DB::table('metric_catalogue_state')->value('version'), $first['version']);
        $this->actingAsWithMfa(User::factory()->create());
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.can_manage', false)->assertJsonMissingPath('data.0.organization_id')->assertJsonMissingPath('data.0.value')->assertJsonPath('data.0.scope.mode_name', $s['mode']->name);
        $this->actingAsWithMfa($s['operator']);
        $second = $this->patchJson($s['root'].'/'.$first['id'], [...$this->metricUpdate($s), 'formula' => 'sum(kills) / sum(deaths), source contrôlée', 'zero_denominator' => 'zero'])->assertOk()->assertJsonPath('data.number', 2)->json('data');
        $this->assertNotSame($first['version'], $second['version']);
        $this->getJson($s['root'].'/'.$first['id'].'/versions/'.$first['version'])->assertOk()->assertJsonPath('data.formula', $s['input']['formula'])->assertJsonPath('data.zero_denominator', 'unavailable');
        $this->getJson($s['root'].'/'.$first['id'].'/history?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.version', $first['version']);
        $this->assertDatabaseCount('metric_definitions', 1);
        $this->assertDatabaseCount('metric_revisions', 2);
        $this->assertDatabaseCount('organization_games', 0);
    }

    public function test_stale_noop_duplicate_and_immutable_identity_do_not_advance_state(): void
    {
        $s = $this->metricScenario(true);
        $path = $s['root'].'/'.$s['record']['id'];
        $update = $this->metricUpdate($s);
        $this->patchJson($path, $update)->assertUnprocessable()->assertJsonValidationErrors('record');
        $this->patchJson($path, [...$update, 'catalogue_version' => (string) Str::uuid(), 'title' => 'Autre titre'])->assertConflict();
        $this->patchJson($path, [...$update, 'version' => (string) Str::uuid(), 'title' => 'Autre titre'])->assertConflict();
        $this->patchJson($path, [...$update, 'game_catalogue_version' => (string) Str::uuid(), 'title' => 'Autre titre'])->assertConflict();
        $this->patchJson($path, [...$update, 'game_id' => $s['game']->id])->assertUnprocessable();
        $this->postJson($s['root'], [...$s['input'], 'catalogue_version' => DB::table('metric_catalogue_state')->value('version')])->assertConflict()->assertJsonPath('code', 'METRIC_CATALOGUE_DUPLICATE');
        $this->assertDatabaseCount('metric_revisions', 1);
        $this->assertSame($s['record']['version'], DB::table('metric_catalogue_state')->value('version'));
    }

    public function test_retirement_and_reactivation_are_new_versions_and_filters_use_latest_only(): void
    {
        $s = $this->metricScenario(true);
        $body = [...$this->metricUpdate($s), 'status' => 'retired'];
        $retired = $this->patchJson($s['root'].'/'.$s['record']['id'], $body)->assertOk()->json('data');
        $this->getJson($s['root'].'?status=active')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($s['root'].'?status=retired')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson($s['root'].'/'.$s['record']['id'], [...$body, 'status' => 'active', 'version' => $retired['version'], 'catalogue_version' => $retired['version']])->assertOk()->assertJsonPath('data.number', 3);
        $this->assertDatabaseCount('metric_revisions', 3);
    }

    public function test_guest_mfa_and_tenant_owner_roles_cannot_write_global_dictionary(): void
    {
        $s = $this->metricScenario();
        $owner = User::factory()->withMfa()->create();
        Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->actingAsWithMfa($owner);
        $this->assertFalse(Gate::forUser($owner)->allows('manage', MetricDefinition::class));
        $this->postJson($s['root'], $s['input'])->assertForbidden();
        $this->assertTrue(Gate::forUser($owner)->allows('viewAny', MetricDefinition::class));
        $this->actingAsWithMfa($s['operator']);
        $this->withSession([MfaSession::PROOF_KEY => null])->postJson($s['root'], $s['input'])->assertForbidden();
        $this->assertDatabaseCount('metric_definitions', 0);
    }

    public function test_guest_cannot_read_dictionary(): void
    {
        $this->getJson('/api/v1/metric-catalogue')->assertUnauthorized();
    }

    public function test_revocation_is_rechecked_in_the_service(): void
    {
        $s = $this->metricScenario();
        $other = User::factory()->withMfa()->create();
        app(PlatformRoleService::class)->configure($other->id, PlatformRoleType::Superadmin, true, $s['operator']->id, 'Second opérateur de test', (string) Str::uuid());
        app(PlatformRoleService::class)->configure($s['operator']->id, PlatformRoleType::Superadmin, false, $other->id, 'Retrait contrôlé de test', (string) Str::uuid());
        $this->expectException(AuthorizationException::class);
        app(MetricCatalogueService::class)->save($s['operator']->id, (string) Str::uuid(), $s['input']);
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalid(): array
    {
        return ['tenant' => [['organization_id' => 'spoof'], 'organization_id'], 'observation' => [['value' => 42], 'value'], 'formula object' => [['formula' => ['eval' => 'x']], 'formula'],
            'code' => [['code' => 'bad code'], 'code'], 'ratio' => [['zero_denominator' => 'not_applicable'], 'zero_denominator'], 'non ratio' => [['aggregation' => 'sum'], 'zero_denominator'],
            'availability' => [['availability' => 'automatic'], 'availability'], 'unit' => [['unit' => '  '], 'unit'], 'reason' => [['reason' => 'short'], 'reason'], 'confirm' => [['confirm_global' => false], 'confirm_global'], 'notes' => [['availability_note' => ''], 'availability_note'],
            'invalid level' => [['aggregation_level' => 'everything'], 'aggregation_level'],
            'scope' => [['role_id' => 'bad-id'], 'role_id'], 'subjective' => [['measurement_kind' => 'unknown'], 'measurement_kind'], 'code length' => [['code' => str_repeat('A', 65)], 'code']];
    }

    /** @param array<string,mixed> $invalid */
    #[DataProvider('invalid')]
    public function test_invalid_definition_is_rejected_without_a_revision(array $invalid, string $field): void
    {
        $s = $this->metricScenario();
        $before = DB::table('metric_catalogue_state')->value('version');
        $this->postJson($s['root'], [...$s['input'], ...$invalid])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('metric_definitions', 0);
        $this->assertDatabaseCount('metric_revisions', 0);
        $this->assertSame($before, DB::table('metric_catalogue_state')->value('version'));
    }

    public function test_reference_kind_game_and_active_status_are_checked(): void
    {
        $s = $this->metricScenario();
        $foreign = GameCatalogueEntry::factory()->create();
        $mode = GameCatalogueEntry::factory()->create(['kind' => 'mode', 'game_id' => $foreign->id, 'scope_key' => $foreign->id, 'definition' => null]);
        $s['input']['game_catalogue_version'] = DB::table('game_catalogue_state')->value('version');
        $this->postJson($s['root'], [...$s['input'], 'mode_id' => $mode->id])->assertUnprocessable();
        $this->postJson($s['root'], [...$s['input'], 'role_id' => $s['mode']->id])->assertUnprocessable();
        $this->postJson($s['root'], [...$s['input'], 'mode_id' => (string) Str::uuid()])->assertNotFound();
        $s['mode']->forceFill(['active' => false])->save();
        $s['input']['game_catalogue_version'] = DB::table('game_catalogue_state')->value('version');
        $this->postJson($s['root'], $s['input'])->assertUnprocessable();
        $this->assertDatabaseCount('metric_revisions', 0);
    }

    public function test_sql_protects_identity_revisions_global_state_and_same_game_scope(): void
    {
        $s = $this->metricScenario(true);
        $row = (array) DB::table('metric_revisions')->first();
        $state = (array) DB::table('metric_catalogue_state')->first();
        foreach (['metric_definitions', 'metric_revisions'] as $table) {
            $this->reject(fn () => DB::table($table)->delete());
            $this->reject(fn () => DB::table($table)->update(['id' => (string) Str::uuid()]));
        }
        $this->reject(fn () => DB::table('metric_catalogue_state')->delete());
        $this->reject(fn () => DB::table('metric_catalogue_state')->update(['version' => (string) Str::uuid()]));
        $this->reject(fn () => DB::table('metric_catalogue_state')->insert([...$state, 'id' => 2]));
        foreach ([['number' => 4], ['zero_denominator' => 'not_applicable'], ['mode_id' => $s['game']->id]] as $invalid) {
            $this->reject(fn () => DB::table('metric_revisions')->insert([...$row, 'id' => (string) Str::uuid(), 'number' => 2, 'catalogue_number' => 2, ...$invalid]));
        }
        $this->assertDatabaseCount('metric_revisions', 1);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_19_094551_create_metric_dictionary.php'))->down();
    }

    public function test_global_definitions_are_excluded_from_private_tenant_exports(): void
    {
        $s = $this->metricScenario(true);
        $org = Organization::factory()->create();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($org->id, 100));
        $this->assertSame([], array_values(array_filter($rows, fn (array $r): bool => str_starts_with($r['table'], 'metric_'))));
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_empty_schema_roundtrip(): void
    {
        $migration = require database_path('migrations/2026_10_19_094551_create_metric_dictionary.php');
        $observations = require database_path('migrations/2026_10_19_113618_create_statistical_observations.php');
        $observations->down();
        $migration->down();
        $this->assertFalse(Schema::hasTable('metric_definitions'));
        $migration->up();
        $observations->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_dictionary_projection_uses_a_bounded_number_of_queries(): void
    {
        $s = $this->metricScenario(true);
        $identity = (array) DB::table('metric_definitions')->first();
        $revision = (array) DB::table('metric_revisions')->first();
        for ($n = 2; $n <= 11; $n++) {
            $id = (string) Str::uuid();
            DB::table('metric_definitions')->insert([...$identity, 'id' => $id, 'code' => 'METRIC_'.$n]);
            DB::table('metric_revisions')->insert([...$revision, 'id' => (string) Str::uuid(), 'metric_id' => $id, 'number' => 1, 'catalogue_number' => $n]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $service = app(MetricCatalogueService::class);
        $page = $service->index(['per_page' => 10]);
        foreach ($page->items() as $row) {
            $service->project($row);
        }$queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $queries);
        $this->assertSame(11, $page->total());
    }

    public function test_a_rejected_revision_rolls_back_identity_and_catalogue_state(): void
    {
        $s = $this->metricScenario();
        $before = DB::table('metric_catalogue_state')->value('version');
        if (DB::getDriverName() === 'sqlite') {
            DB::unprepared("CREATE TRIGGER deny_metric BEFORE INSERT ON metric_revisions BEGIN SELECT RAISE(ABORT,'Unavailable journal'); END");
        } else {
            DB::unprepared("CREATE FUNCTION deny_metric() RETURNS trigger LANGUAGE plpgsql AS \$\$ BEGIN RAISE EXCEPTION 'Unavailable journal'; END \$\$");
            DB::unprepared('CREATE TRIGGER deny_metric BEFORE INSERT ON metric_revisions FOR EACH ROW EXECUTE FUNCTION deny_metric()');
        }
        try {
            $this->postJson($s['root'], $s['input'])->assertStatus(500);
            $this->assertDatabaseCount('metric_definitions', 0);
            $this->assertDatabaseCount('metric_revisions', 0);
            $this->assertSame($before, DB::table('metric_catalogue_state')->value('version'));
        } finally {
            DB::unprepared('DROP TRIGGER deny_metric'.(DB::getDriverName() === 'pgsql' ? ' ON metric_revisions' : ''));
            if (DB::getDriverName() === 'pgsql') {
                DB::unprepared('DROP FUNCTION deny_metric()');
            }
        }
    }

    public function test_aggregation_level_is_versioned_independently_of_the_operator(): void
    {
        $s = $this->metricScenario(true);
        $this->getJson($s['root'].'/'.$s['record']['id'])->assertOk()->assertJsonPath('data.aggregation_level', 'game');
        $row = $this->patchJson($s['root'].'/'.$s['record']['id'], [...$this->metricUpdate($s), 'aggregation_level' => 'period'])->assertOk()->assertJsonPath('data.aggregation_level', 'period')->json('data');
        $this->getJson($s['root'].'/'.$s['record']['id'].'/versions/'.$s['record']['version'])->assertOk()->assertJsonPath('data.aggregation_level', 'game');
        $this->patchJson($s['root'].'/'.$s['record']['id'], [...$this->metricUpdate($s), 'version' => $row['version'], 'catalogue_version' => $row['version'], 'title' => 'Nouvelle désignation'])->assertOk()->assertJsonPath('data.aggregation_level', 'period');
    }

    /** @param \Closure():mixed $write */
    private function reject(\Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL must reject this mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
