<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\PersonStatus;
use App\Modules\Performance\Application\Services\ObservationService;
use App\Modules\Performance\Application\Validation\MetricCatalogueInput;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\OrganizationGame;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchScores;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ObservationTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMetricCatalogue,ProvidesObservations,ProvidesPeopleAccess;
    use ProvidesMatchScores;

    public function test_zero_missing_correction_and_exact_history_preserve_original_source(): void
    {
        $s = $this->observationScenario(true);
        $path = $s['root'].'/'.$s['record']['id'];
        $this->assertSame('0', $s['record']['value']);
        $this->assertSame('manual', $s['record']['acquired_via']);
        $row = $this->patchJson($path, $this->observationCorrection($s))->assertOk()->assertJsonPath('data.value', '12.125')->assertJsonPath('data.number', 2)->json('data');
        $this->getJson($path.'/versions/'.$s['record']['version'])->assertOk()->assertJsonPath('data.value', '0')->assertJsonPath('data.source_label', $s['input']['source_label']);
        $this->patchJson($path, [...$this->observationCorrection($s), 'version' => $row['version'], 'availability' => 'unavailable', 'value' => null, 'missing_reason' => 'Source insuffisante pour mesurer.'])->assertOk()->assertJsonPath('data.value', null)->assertJsonPath('data.availability', 'unavailable');
        $this->getJson($path.'/history?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 3)->assertJsonPath('data.0.value', '12.125');
        $this->patchJson($path, $this->observationCorrection($s))->assertConflict()->assertJsonPath('code', 'OBSERVATION_STALE');
        $this->assertDatabaseCount('statistical_observations', 1);
        $this->assertDatabaseCount('observation_revisions', 3);
        $this->assertStringNotContainsString('Note privée', DB::table('observation_revisions')->firstOrFail()->notes);
        $this->assertStringNotContainsString('Grille privée', DB::table('statistical_observations')->firstOrFail()->source_label);
    }

    public function test_reader_owner_technical_and_unverified_cannot_write_without_explicit_grants(): void
    {
        $s = $this->observationScenario(true);
        $ctx = $this->personContext($s['org'], $s['reader']);
        $this->assertTrue(Gate::forUser($s['reader'])->allows('viewAny', [StatisticalObservation::class, $ctx]));
        $this->assertFalse(Gate::forUser($s['reader'])->allows('create', [StatisticalObservation::class, $ctx]));
        $this->assertFalse(Gate::forUser($s['reader'])->allows('correct', [StatisticalObservation::class, $ctx]));
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($s['root'], $s['input'])->assertForbidden();
        $this->patchJson($s['root'].'/'.$s['record']['id'], $this->observationCorrection($s))->assertForbidden();
        $owner = User::query()->where('id', $s['org']->owner_user_id)->firstOrFail();
        $this->actingAsWithMfa($owner);
        $this->getJson($s['root'])->assertForbidden();
        $this->actingAsWithMfa($s['operator']);
        $this->getJson($s['root'])->assertNotFound();
        $this->actingAsWithMfa(User::factory()->unverified()->withMfa()->create());
        $this->getJson($s['root'])->assertNotFound();
    }

    #[Group('tenant-isolation')]
    public function test_cross_tenant_person_match_and_observation_are_not_exposed(): void
    {
        $s = $this->observationScenario(true);
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write', 'sports.statistics.correct']);
        $path = '/api/v1/tenants/'.$other->id.'/observations/'.$s['record']['id'];
        $this->getJson($path)->assertNotFound();
        $this->getJson($path.'/history')->assertNotFound();
        $this->getJson($path.'/versions/'.$s['record']['version'])->assertNotFound();
        $this->patchJson($path, $this->observationCorrection($s))->assertNotFound();
        $foreign = Person::factory()->create();
        $this->postJson($s['root'], [...$s['input'], 'person_id' => $foreign->id])->assertNotFound();
        $foreignMatch = MatchRecord::factory()->create();
        $this->postJson($s['root'], [...$s['input'], 'match_id' => $foreignMatch->id])->assertNotFound();
        $this->assertDatabaseCount('statistical_observations', 1);
    }

    #[Group('tenant-isolation')]
    public function test_multi_tenant_corrector_cannot_read_or_modify_observations_and_versions_under_another_tenant(): void
    {
        $s = $this->observationScenario(true);
        $other = $this->otherTenantObservation($s);
        $before = $this->observationSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();

        foreach ([[$s, $other], [$other, $s]] as [$current, $foreign]) {
            $ownPath = $current['root'].'/'.$current['record']['id'];
            $path = $current['root'].'/'.$foreign['record']['id'];
            $this->getJson($ownPath)->assertOk()->assertJsonPath('data.value', '0');
            $this->getJson($ownPath.'/versions/'.$current['record']['version'])->assertOk();
            $this->getJson($path)->assertNotFound();
            $this->getJson($path.'/history')->assertNotFound();
            $this->getJson($path.'/versions/'.$foreign['record']['version'])->assertNotFound();
            $this->patchJson($path, $this->observationCorrection($foreign))->assertNotFound();
            $this->postJson($current['root'], [...$current['input'], 'person_id' => $foreign['person']->id])->assertNotFound();
        }

        $this->assertSame($before, $this->observationSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->assertDatabaseCount('statistical_observations', 2);
        $this->assertDatabaseCount('observation_revisions', 2);
    }

    #[Group('tenant-isolation')]
    #[TestWith(['membership', 404])]
    #[TestWith(['role', 403])]
    #[TestWith(['read', 403])]
    public function test_revocation_blocks_warmed_observations_history_and_writes_without_revoking_beta(string $revocation, int $status): void
    {
        $s = $this->observationScenario(true);
        $other = $this->otherTenantObservation($s);
        $path = $s['root'].'/'.$s['record']['id'];
        foreach (['', '/history', '/versions/'.$s['record']['version']] as $suffix) {
            $this->getJson($path.$suffix)->assertOk();
        }
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 1);
        $before = $this->observationSnapshot($s['org']->id);
        $auditCount = DB::table('organization_audit_events')->count();
        if ($revocation === 'membership') {
            OrganizationMembership::query()->whereKey($s['assignment']->membership_id)->firstOrFail()->forceFill(['status' => 'revoked'])->save();
        } elseif ($revocation === 'role') {
            $s['assignment']->forceFill(['status' => 'revoked'])->save();
        } else {
            $s['assignment']->role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.statistics.write', 'sports.statistics.correct']])->save();
        }

        foreach ([$s['root'], $path, $path.'/history', $path.'/versions/'.$s['record']['version'], $s['root'].'/choices?kind=people'] as $url) {
            $this->getJson($url)->assertStatus($status)->assertJsonMissingPath('data')->assertDontSee('Note privée');
        }
        $this->postJson($s['root'], [...$s['input'], 'source_label' => 'Nouvelle source'])->assertStatus($status);
        $this->patchJson($path, $this->observationCorrection($s))->assertStatus($status);
        $this->assertSame($before, $this->observationSnapshot($s['org']->id));
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->getJson($other['root'].'/'.$other['record']['id'])->assertOk()->assertJsonPath('data.value', '0');
        $this->patchJson($other['root'].'/'.$other['record']['id'], $this->observationCorrection($other))->assertOk()->assertJsonPath('data.value', '12.125');
        $this->assertSame($before, $this->observationSnapshot($s['org']->id));
        $this->assertDatabaseCount('statistical_observations', 2);
        $this->assertDatabaseCount('observation_revisions', 3);
    }

    #[Group('tenant-isolation')]
    public function test_removing_creation_permission_preserves_reads_and_correction_but_blocks_creation_and_choices(): void
    {
        $s = $this->observationScenario(true);
        $path = $s['root'].'/'.$s['record']['id'];
        $this->getJson($path)->assertOk();
        $before = $this->observationSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $s['assignment']->role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.correct']])->save();

        $this->getJson($path.'/history')->assertOk();
        $this->getJson($path.'/versions/'.$s['record']['version'])->assertOk()->assertJsonPath('data.value', '0');
        $this->postJson($s['root'], [...$s['input'], 'source_label' => 'Nouvelle source'])->assertForbidden();
        $this->getJson($s['root'].'/choices?kind=people')->assertForbidden();
        $this->assertSame($before, $this->observationSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->patchJson($path, $this->observationCorrection($s))->assertOk()->assertJsonPath('data.value', '12.125')->assertJsonPath('data.number', 2);
        $this->getJson($path.'/versions/'.$s['record']['version'])->assertOk()->assertJsonPath('data.value', '0');
        $this->assertDatabaseCount('statistical_observations', 1);
        $this->assertDatabaseCount('observation_revisions', 2);
        $this->assertSame($auditCount + 1, DB::table('organization_audit_events')->count());
    }

    #[Group('tenant-isolation')]
    public function test_removing_correction_permission_preserves_creation_without_overwriting_existing_history(): void
    {
        $s = $this->observationScenario(true);
        $path = $s['root'].'/'.$s['record']['id'];
        $this->getJson($path)->assertOk();
        $before = $this->observationSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $s['assignment']->role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write']])->save();

        $this->patchJson($path, $this->observationCorrection($s))->assertForbidden();
        $this->getJson($path.'/history')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame($before, $this->observationSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->postJson($s['root'], [...$s['input'], 'source_label' => 'Nouvelle source'])->assertCreated()->assertJsonPath('data.value', '0');
        $this->getJson($path)->assertOk()->assertJsonPath('data.version', $s['record']['version'])->assertJsonPath('data.value', '0');
        $this->getJson($path.'/versions/'.$s['record']['version'])->assertOk()->assertJsonPath('data.value', '0');
        $this->assertDatabaseCount('statistical_observations', 2);
        $this->assertDatabaseCount('observation_revisions', 2);
    }

    #[Group('tenant-isolation')]
    public function test_same_tenant_version_ids_cannot_be_read_or_applied_to_another_observation(): void
    {
        $s = $this->observationScenario(true);
        $other = $this->postJson($s['root'], [...$s['input'], 'source_label' => 'Autre observation'])->assertCreated()->json('data');
        $before = $this->observationSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();

        $this->getJson($s['root'].'/'.$s['record']['id'].'/versions/'.$other['version'])->assertNotFound();
        $this->getJson($s['root'].'/'.$other['id'].'/versions/'.$s['record']['version'])->assertNotFound();
        $this->patchJson($s['root'].'/'.$s['record']['id'], [...$this->observationCorrection($s), 'version' => $other['version']])->assertConflict()->assertJsonPath('code', 'OBSERVATION_STALE');
        $this->assertSame($before, $this->observationSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->getJson($s['root'].'/'.$other['id'].'/versions/'.$other['version'])->assertOk()->assertJsonPath('data.value', '0');
        $this->assertDatabaseCount('observation_revisions', 2);
    }

    #[Group('tenant-isolation')]
    public function test_restricting_a_previously_visible_match_hides_observation_versions_and_blocks_corrections(): void
    {
        $s = $this->observationScenario();
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id,
            'created_by' => $s['reader']->id, 'kind' => 'scrim', 'visibility' => 'organization',
            'sharing_authorized_by' => $s['reader']->id, 'sharing_authorized_at' => now()]);
        $row = $this->postJson($s['root'], [...$s['input'], 'match_id' => $match->id, 'source_kind' => 'scrim'])->assertCreated()->json('data');
        $s['record'] = $row;
        $path = $s['root'].'/'.$row['id'];
        foreach (['', '/history', '/versions/'.$row['version']] as $suffix) {
            $this->getJson($path.$suffix)->assertOk();
        }
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($s['root'].'/choices?kind=matches&game_id='.$s['game']->id)->assertOk()->assertJsonPath('meta.total', 1);
        $before = $this->observationSnapshot();
        $auditCount = DB::table('organization_audit_events')->count();
        $match->forceFill(['visibility' => 'private', 'version' => (string) Str::uuid()])->save();

        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($s['root'].'/choices?kind=matches&game_id='.$s['game']->id)->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['', '/history', '/versions/'.$row['version']] as $suffix) {
            $this->getJson($path.$suffix)->assertNotFound()->assertJsonMissingPath('data');
        }
        $this->patchJson($path, $this->observationCorrection($s))->assertNotFound();
        $this->postJson($s['root'], [...$s['input'], 'match_id' => $match->id, 'source_kind' => 'scrim', 'source_label' => 'Autre source'])->assertNotFound();
        $this->assertSame($before, $this->observationSnapshot());
        $this->assertSame($auditCount, DB::table('organization_audit_events')->count());
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($path)->assertOk()->assertJsonPath('data.value', '0');
        $this->getJson($path.'/versions/'.$row['version'])->assertOk()->assertJsonPath('data.value', '0');
    }

    /** @param array<string, mixed> $s
     * @return array<string, mixed> */
    private function otherTenantObservation(array $s): array
    {
        $org = Organization::factory()->create();
        OrganizationGame::factory()->create(['organization_id' => $org->id, 'game_id' => $s['game']->id, 'enabled' => true]);
        $assignment = $this->grantPeopleAccess($org, $s['writer'], $s['assignment']->role->permissions);
        $person = Person::factory()->create(['organization_id' => $org->id]);
        $root = '/api/v1/tenants/'.$org->id.'/observations';
        $input = [...$s['input'], 'person_id' => $person->id];
        $record = $this->postJson($root, $input)->assertCreated()->json('data');

        return [...$s, ...compact('org', 'assignment', 'person', 'root', 'input', 'record')];
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function observationSnapshot(?string $organization = null): array
    {
        $snapshot = [];
        foreach (['statistical_observations', 'observation_revisions'] as $table) {
            $snapshot[$table] = array_values(DB::table($table)->when($organization !== null, fn ($query) => $query->where('organization_id', $organization))
                ->orderBy('id')->get()->map(fn (object $row): array => get_object_vars($row))->all());
        }

        return $snapshot;
    }

    public function test_linked_observation_disappears_when_match_audience_changes(): void
    {
        $s = $this->observationScenario();
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id, 'created_by' => $s['writer']->id, 'kind' => 'scrim', 'visibility' => 'private']);
        $row = $this->postJson($s['root'], [...$s['input'], 'match_id' => $match->id, 'source_kind' => 'scrim'])->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['root'])->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($s['root'].'/'.$row['id'])->assertNotFound();
        $this->getJson($s['root'].'/'.$row['id'].'/history')->assertNotFound();
        $this->postJson($s['root'], [...$s['input'], 'match_id' => $match->id])->assertForbidden();
        $this->actingAsWithMfa($s['writer']);
        $this->getJson($s['root'].'/'.$row['id'])->assertOk();
    }

    public function test_revoked_grant_is_rechecked_by_direct_service_with_old_context(): void
    {
        $s = $this->observationScenario();
        $context = $this->personContext($s['org'], $s['writer']);
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->expectException(AuthorizationException::class);
        app(ObservationService::class)->save($context, $s['input']);
    }

    /** @return array<string,array{array<string,mixed>}> */
    public static function invalid(): array
    {
        return ['float' => [['value' => 1.2]], 'scientific' => [['value' => '1e3']], 'precision' => [['value' => '0.0000001']], 'overflow' => [['value' => '1000000000000']], 'missing reason' => [['availability' => 'unavailable', 'value' => null]], 'missing is not zero' => [['availability' => 'unavailable', 'value' => '0', 'missing_reason' => 'Absente']], 'available reason' => [['missing_reason' => 'Absente']], 'future' => [['observed_on' => '2026-10-20']], 'period' => [['period_start' => '2026-10-19']], 'source blank' => [['source_label' => '   ']], 'source enum' => [['source_kind' => 'import']], 'private injection' => [['organization_id' => '11111111-1111-4111-8111-111111111111']], 'import injection' => [['acquired_via' => 'import']], 'invalid subjective' => [['source_kind' => 'assessment']], 'unlinked part' => [['match_game_id' => '11111111-1111-4111-8111-111111111111']], 'wrong level' => [['round_number' => 2]], 'sample' => [['sample_size' => 0]], 'reason blank' => [['reason' => '            ']]];
    }

    /** @param array<string,mixed> $invalid */
    #[DataProvider('invalid')]
    public function test_invalid_input_never_creates_partial_observation(array $invalid): void
    {
        $s = $this->observationScenario();
        $this->postJson($s['root'], [...$s['input'], ...$invalid])->assertUnprocessable();
        $this->assertDatabaseCount('statistical_observations', 0);
        $this->assertDatabaseCount('observation_revisions', 0);
    }

    public function test_wrong_metric_version_and_immutable_source_updates_are_rejected(): void
    {
        $s = $this->observationScenario(true);
        $this->postJson($s['root'], [...$s['input'], 'metric_version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'OBSERVATION_METRIC');
        $this->patchJson($s['root'].'/'.$s['record']['id'], [...$this->observationCorrection($s), 'source_label' => 'Réécriture interdite'])->assertUnprocessable()->assertJsonValidationErrors('source_label');
        $this->patchJson($s['root'].'/'.$s['record']['id'], [...$this->observationCorrection($s), 'value' => '0', 'notes' => $s['input']['notes']])->assertUnprocessable()->assertJsonValidationErrors('record');
    }

    public function test_voiding_and_reactivating_preserves_versions(): void
    {
        $s = $this->observationScenario(true);
        $path = $s['root'].'/'.$s['record']['id'];
        $row = $this->patchJson($path, [...$this->observationCorrection($s), 'status' => 'voided'])->assertOk()->json('data');
        $this->getJson($s['root'].'?status=active')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($s['root'].'?status=voided')->assertOk()->assertJsonPath('meta.total', 1);
        $this->patchJson($path, [...$this->observationCorrection($s), 'version' => $row['version'], 'status' => 'active'])->assertOk()->assertJsonPath('data.number', 3);
    }

    public function test_sql_and_portability_preserve_tenant_provenance_and_history(): void
    {
        $s = $this->observationScenario(true);
        $row = (array) DB::table('observation_revisions')->first();
        foreach (['statistical_observations', 'observation_revisions'] as $table) {
            $this->reject(fn () => DB::table($table)->delete());
            $this->reject(fn () => DB::table($table)->update(['id' => (string) Str::uuid()]));
        }
        foreach ([['number' => 3], ['number' => 2, 'base_version' => null], ['number' => 2, 'base_version' => (string) Str::uuid()], ['organization_id' => (string) Str::uuid()], ['availability' => 'unavailable']] as $bad) {
            $this->reject(fn () => DB::table('observation_revisions')->insert([...$row, 'id' => (string) Str::uuid(), ...$bad]));
        }
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        app(PortabilityRepository::class)->assertCatalog();
        $rows = iterator_to_array(app(PortabilityRepository::class)->rows($s['org']->id, 1000));
        $this->assertContains('statistical_observations', array_column($rows, 'table'));
        $this->assertContains('observation_revisions', array_column($rows, 'table'));
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_19_113618_create_statistical_observations.php'))->down();
    }

    public function test_audit_failure_rolls_back_identity_and_revision(): void
    {
        $s = $this->observationScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(ObservationService::class)->save($this->personContext($s['org'], $s['writer']), $s['input']);
            $this->fail('Audit must abort.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('statistical_observations', 0);
        $this->assertDatabaseCount('observation_revisions', 0);
    }

    public function test_empty_schema_roundtrip_and_choices_are_bounded(): void
    {
        $statisticalExecution = require database_path('migrations/2026_10_19_175141_create_statistical_import_executions.php');
        $statisticalCalculations = require database_path('migrations/2026_10_19_183908_create_statistical_calculations.php');
        $statisticalCalculations->down();
        $statisticalExecution->down();
        $migration = require database_path('migrations/2026_10_19_113618_create_statistical_observations.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('statistical_observations'));
        $migration->up();
        $statisticalExecution->up();
        $statisticalCalculations->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $s = $this->observationScenario();
        $this->getJson($s['root'].'/choices?kind=people')->assertOk()->assertJsonPath('data.0.id', $s['person']->id);
        Person::factory()->count(10)->create(['organization_id' => $s['org']->id]);
        $next = $this->getJson($s['root'].'/choices?kind=people')->assertOk()->assertJsonPath('meta.last_page', 2)->json('links.next');
        $this->assertStringContainsString($s['root'].'/choices?', $next);
        $this->assertStringContainsString('kind=people', $next);
        $this->getJson($s['root'].'/choices?kind=matches')->assertUnprocessable()->assertJsonValidationErrors('game_id');
        $this->getJson($s['root'].'/choices?kind=parts')->assertUnprocessable()->assertJsonValidationErrors('match_id');
        $this->getJson($s['root'].'?per_page=11')->assertUnprocessable();
    }

    public function test_correction_permission_does_not_grant_creation_or_overwrite_import_lineage(): void
    {
        $s = $this->observationScenario(true);
        $corrector = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $corrector, ['sports.read', 'sports.statistics.read', 'sports.statistics.correct']);
        $root = (array) DB::table('statistical_observations')->firstOrFail();
        $original = (array) DB::table('observation_revisions')->firstOrFail();
        $id = (string) Str::uuid();
        DB::table('statistical_observations')->insert([...$root, 'id' => $id, 'acquired_via' => 'import', 'origin_reference' => 'CSV_TEST_ROW_12']);
        DB::table('observation_revisions')->insert([...$original, 'id' => (string) Str::uuid(), 'observation_id' => $id]);
        $this->actingAsWithMfa($corrector);
        $this->postJson($s['root'], $s['input'])->assertForbidden();
        $before = $this->getJson($s['root'].'/'.$id)->assertOk()->json('data');
        $this->patchJson($s['root'].'/'.$id, [...$this->observationCorrection($s), 'version' => $before['version']])->assertOk()->assertJsonPath('data.acquired_via', 'import')->assertJsonPath('data.origin_reference', 'CSV_TEST_ROW_12');
        $this->getJson($s['root'].'/'.$id.'/versions/'.$before['version'])->assertOk()->assertJsonPath('data.value', '0');
        $this->assertDatabaseCount('observation_revisions', 3);
    }

    public function test_inactive_metric_departed_person_and_wrong_context_are_rejected(): void
    {
        $s = $this->observationScenario();
        $foreign = GameCatalogueEntry::factory()->create(['kind' => 'mode', 'game_id' => $otherGame = GameCatalogueEntry::factory()->create()->id, 'scope_key' => $otherGame, 'definition' => null]);
        $this->postJson($s['root'], [...$s['input'], 'mode_id' => $foreign->id])->assertUnprocessable();
        $s['person']->forceFill(['status' => PersonStatus::Former])->save();
        $this->postJson($s['root'], $s['input'])->assertConflict();
        $s['person']->forceFill(['status' => PersonStatus::Active])->save();
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->assertOk()->json('meta');
        $body = [...$s['metric'], 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true, 'status' => 'retired', 'reason' => 'Retrait après contrôle de la disponibilité.'];
        $allowed = MetricCatalogueInput::rules('update');
        $this->patchJson('/api/v1/metric-catalogue/'.$s['metric']['id'], array_intersect_key($body, $allowed))->assertOk();
        $this->actingAsWithMfa($s['writer']);
        $this->postJson($s['root'], $s['input'])->assertConflict()->assertJsonPath('code', 'OBSERVATION_METRIC');
    }

    public function test_organisation_wide_reader_does_not_gain_match_management_audience(): void
    {
        $s = $this->observationScenario();
        $hidden = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id, 'created_by' => $s['reader']->id, 'kind' => 'scrim', 'visibility' => 'private']);
        $this->postJson($s['root'], [...$s['input'], 'match_id' => $hidden->id, 'source_kind' => 'scrim'])->assertNotFound();
        $this->getJson($s['root'].'/choices?kind=matches&game_id='.$s['game']->id)->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_page_projection_stays_bounded_and_preserves_metric_snapshot_after_edit(): void
    {
        $s = $this->observationScenario(true);
        $service = app(ObservationService::class);
        $ctx = $this->personContext($s['org'], $s['writer']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = $service->index($ctx, []);
        $labels = $service->labels($ctx, $rows->items());
        foreach ($rows->items() as $row) {
            $service->project($row, $labels);
        }
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        for ($i = 0; $i < 10; $i++) {
            $this->postJson($s['root'], $s['input'])->assertCreated();
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = $service->index($ctx, []);
        $labels = $service->labels($ctx, $rows->items());
        foreach ($rows->items() as $row) {
            $service->project($row, $labels);
        }
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
        $this->assertSame(11, $rows->total());
        $this->assertCount(10, $rows->items());
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->json('meta');
        $body = [...$s['metric'], 'formula' => 'Nouvelle formule documentaire', 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true, 'reason' => 'Précision documentaire après la saisie.'];
        $this->patchJson('/api/v1/metric-catalogue/'.$s['metric']['id'], array_intersect_key($body, MetricCatalogueInput::rules('update')))->assertOk();
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['root'].'/'.$s['record']['id'])->assertOk()->assertJsonPath('data.context.metric.formula', $s['metric']['formula'])->assertJsonPath('data.metric_version', $s['metric']['version']);
    }

    public function test_game_level_requires_an_existing_part_of_the_same_visible_match(): void
    {
        $base = $this->observationScenario();
        $s = $this->scoreScenario('bo1');
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated();
        $part = DB::table('match_game_instances')->where('match_id', $s['record']['id'])->firstOrFail();
        $s['writer']->refresh();
        $assignment = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->firstOrFail();
        $assignment->role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write']])->save();
        $this->actingAsWithMfa($base['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->json('meta');
        $metricBody = [...$base['metric'], 'game_id' => $s['game']->id, 'aggregation_level' => 'game', 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true];
        $metric = $this->postJson('/api/v1/metric-catalogue', array_intersect_key($metricBody, MetricCatalogueInput::rules('store')))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['writer']);
        $root = '/api/v1/tenants/'.$s['org']->id.'/observations';
        $input = [...$base['input'], 'metric_id' => $metric['id'], 'metric_version' => $metric['version'], 'person_id' => $s['person']->id, 'source_kind' => 'scrim', 'match_id' => $s['record']['id'], 'match_game_id' => null, 'period_start' => '2026-10-18', 'period_end' => '2026-10-18', 'observed_on' => '2026-10-18'];
        $this->postJson($root, $input)->assertUnprocessable();
        $this->postJson($root, [...$input, 'match_game_id' => (string) Str::uuid()])->assertNotFound();
        $this->postJson($root, [...$input, 'match_game_id' => $part->id])->assertCreated()->assertJsonPath('data.match_game_id', $part->id);
        $this->getJson($root.'/choices?kind=parts&match_id='.$s['record']['id'])->assertOk()->assertJsonPath('data.0.id', $part->id);
        $this->actingAsWithMfa($base['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->json('meta');
        $subjective = [...$metric, 'measurement_kind' => 'subjective', 'formula' => 'Grille documentée de communication.', 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true, 'reason' => 'Évaluation humaine explicitement déclarée.'];
        $subjective = $this->patchJson('/api/v1/metric-catalogue/'.$metric['id'], array_intersect_key($subjective, MetricCatalogueInput::rules('update')))->assertOk()->json('data');
        $this->actingAsWithMfa($s['writer']);
        $assessment = [...$input, 'metric_version' => $subjective['version'], 'source_kind' => 'assessment', 'match_game_id' => $part->id];
        $this->postJson($root, [...$assessment, 'source_kind' => 'scrim'])->assertUnprocessable();
        $this->postJson($root, $assessment)->assertCreated()->assertJsonPath('data.source_kind', 'assessment')->assertJsonPath('data.context.metric.measurement_kind', 'subjective');
        $this->actingAsWithMfa($base['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->json('meta');
        $session = [...$subjective, 'aggregation_level' => 'session', 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true, 'reason' => 'Grille applicable à une séance documentée.'];
        $session = $this->patchJson('/api/v1/metric-catalogue/'.$metric['id'], array_intersect_key($session, MetricCatalogueInput::rules('update')))->assertOk()->json('data');
        $this->actingAsWithMfa($s['writer']);
        $sessionInput = [...$assessment, 'metric_version' => $session['version'], 'match_id' => null, 'match_game_id' => null];
        $this->postJson($root, $sessionInput)->assertUnprocessable();
        $this->postJson($root, [...$sessionInput, 'session_label' => 'Séance communication A'])->assertCreated()->assertJsonPath('data.context.session_label', 'Séance communication A');

    }

    public function test_period_filters_work_separately_and_creation_grant_does_not_grant_correction(): void
    {
        $s = $this->observationScenario(true);
        $this->getJson($s['root'].'?to=2026-10-10')->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson($s['root'].'?from=2026-10-19')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson($s['root'].'?from=2026-10-19&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $s['assignment']->role->forceFill(['permissions' => ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write']])->save();
        $this->assertTrue(Gate::forUser($s['writer'])->allows('create', [StatisticalObservation::class, $this->personContext($s['org'], $s['writer'])]));
        $this->assertFalse(Gate::forUser($s['writer'])->allows('correct', [StatisticalObservation::class, $this->personContext($s['org'], $s['writer'])]));
        $this->patchJson($s['root'].'/'.$s['record']['id'], $this->observationCorrection($s))->assertForbidden();
    }

    /** @param \Closure():mixed $write */
    private function reject(\Closure $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL must refuse mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
