<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Performance\Application\Contracts\StatisticalCalculationQueue;
use App\Modules\Performance\Application\Services\StatisticalCalculationProcessor;
use App\Modules\Performance\Application\Validation\MetricCatalogueInput;
use App\Modules\Performance\Domain\Models\StatisticalCalculationRevision;
use App\Modules\Performance\Infrastructure\Queue\CalculateStatistics;
use App\Modules\Performance\Infrastructure\Queue\RecalculateObservationStatistics;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class StatisticalCalculationTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMetricCatalogue,ProvidesObservations,ProvidesPeopleAccess;

    /** @return array<string,mixed> */
    private function scenario(string $operation = 'sum', bool $fakeQueue = true): array
    {
        $s = $this->observationScenario();
        config(['statistical_calculations.processing_enabled' => true]);
        if ($fakeQueue) {
            Queue::fake([CalculateStatistics::class, RecalculateObservationStatistics::class]);
        }
        $metric = $this->metric($s, 'COUNT', $operation);

        return [...$s, 'metric' => $metric, 'calculations' => '/api/v1/tenants/'.$s['org']->id.'/statistical-calculations'];
    }

    /** @param array<string,mixed> $s
     * @param array<string,list<string>>|null $components
     * @return array<string,mixed> */
    private function metric(array $s, string $code, string $aggregation = 'sum', ?array $components = null, string $zero = 'unavailable'): array
    {
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->assertOk()->json('meta');
        $input = [...$s['input'], 'game_id' => $s['game']->id, 'code' => $code, 'mode_id' => null, 'role_id' => null, 'release_id' => null,
            'title' => $code, 'definition' => 'Mesure additive documentée.', 'unit' => 'count', 'aggregation' => $aggregation,
            'formula' => 'Description documentaire, jamais exécutée.', 'aggregation_level' => 'period', 'zero_denominator' => $aggregation === 'ratio' ? $zero : 'not_applicable',
            'measurement_kind' => 'objective', 'availability' => 'manual', 'availability_note' => 'Donnée documentée.', 'status' => 'active',
            'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'], 'confirm_global' => true,
            'reason' => 'Définition validée pour les calculs statistiques.'];
        if ($components !== null) {
            $input['ratio_components'] = $components;
        }
        $metric = $this->postJson('/api/v1/metric-catalogue', array_intersect_key($input, MetricCatalogueInput::rules('store')))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['writer']);

        return $metric;
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed>|null $metric
     * @param array<string,mixed> $extra
     * @return array<string,mixed> */
    private function observation(array $s, string $value, int $day, ?array $metric = null, array $extra = []): array
    {
        $metric ??= $s['metric'];
        $date = '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);

        return $this->postJson($s['root'], [...$s['input'], 'metric_id' => $metric['id'], 'metric_version' => $metric['version'],
            'value' => $value, 'period_start' => $date, 'period_end' => $date, ...$extra])->assertCreated()->json('data');
    }

    /** @param array<string,mixed> $s
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed>|null $metric
     * @return array<string,mixed> */
    private function requestCalculation(array $s, array $rows, ?array $metric = null): array
    {
        $metric ??= $s['metric'];

        return $this->postJson($s['calculations'], ['metric_id' => $metric['id'], 'metric_version' => $metric['version'],
            'observations' => array_column($rows, 'id'), 'confirm' => true])->assertOk()->json('data');
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $calculation */
    private function process(array $s, array $calculation): void
    {
        app(StatisticalCalculationProcessor::class)->process($this->personContext($s['org'], $s['writer']), $calculation['id'], $calculation['version']);
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $row */
    private function correct(array $s, array $row, string $value): void
    {
        $this->patchJson($s['root'].'/'.$row['id'], ['version' => $row['version'], 'value' => $value, 'availability' => 'available', 'missing_reason' => null,
            'notes' => null, 'status' => 'active', 'reason' => 'Correction contrôlée pour recalculer les agrégats.'])->assertOk();
    }

    public function test_confirmation_queues_a_bounded_sample_and_retry_reuses_the_same_calculation(): void
    {
        $s = $this->scenario();
        $rows = [$this->observation($s, '2', 1), $this->observation($s, '4', 2)];
        $calculation = $this->requestCalculation($s, $rows);
        $this->assertSame('queued', $calculation['status']);
        $this->assertNull($calculation['result']);
        $this->assertSame($calculation['id'], $this->requestCalculation($s, array_reverse($rows))['id']);
        $this->assertDatabaseCount('statistical_calculations', 1);
        $this->assertDatabaseCount('statistical_calculation_sources', 2);
        $this->assertDatabaseCount('statistical_calculation_revisions', 0);
        Queue::assertPushed(CalculateStatistics::class, fn ($job) => $job->context->tenantId === $s['org']->id && $job->context->actorUserId === $s['writer']->id && $job->queue === 'reports' && $job->afterCommit === true);
        Queue::assertPushed(CalculateStatistics::class, 1);
    }

    public function test_worker_persists_an_exact_versioned_result_and_ignores_replayed_jobs(): void
    {
        $s = $this->scenario();
        $calculation = $this->requestCalculation($s, [$this->observation($s, '0.1', 1), $this->observation($s, '0.2', 2)]);
        $this->process($s, $calculation);
        $this->process($s, $calculation);
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.result.value', '0.3')
            ->assertJsonPath('data.result.sample_size', 6)->assertJsonPath('data.result.engine_version', 'stat-004.v1')->assertJsonPath('data.status', 'completed')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('statistical_calculation_revisions', 1);
        $raw = DB::table('statistical_calculation_revisions')->firstOrFail();
        $this->assertStringNotContainsString('0.3', $raw->result);
    }

    public function test_ratio_recipe_pins_component_versions_and_combines_them_without_naive_average(): void
    {
        $s = $this->scenario();
        $denominator = $this->metric($s, 'DENOMINATOR');
        $ratio = $this->metric($s, 'RATIO', 'ratio', ['numerator' => [$s['metric']['id']], 'denominator' => [$denominator['id']]]);
        $this->assertSame($s['metric']['version'], $ratio['ratio_components']['numerator'][0]['version']);
        $rows = [$this->observation($s, '1', 1), $this->observation($s, '1', 1, $denominator), $this->observation($s, '9', 2), $this->observation($s, '99', 2, $denominator)];
        $calculation = $this->requestCalculation($s, $rows, $ratio);
        $this->process($s, $calculation);
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.result.value', '0.1')
            ->assertJsonPath('data.result.numerator', '10')->assertJsonPath('data.result.denominator', '100')->assertJsonPath('data.result.sample_size', 6);
    }

    public function test_incomplete_ratio_does_not_fabricate_a_zero(): void
    {
        $s = $this->scenario();
        $denominator = $this->metric($s, 'DENOMINATOR');
        $ratio = $this->metric($s, 'RATIO', 'ratio', ['numerator' => [$s['metric']['id']], 'denominator' => [$denominator['id']]], 'zero');
        $calculation = $this->requestCalculation($s, [$this->observation($s, '1', 1)], $ratio);
        $this->process($s, $calculation);
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.result.value', null)
            ->assertJsonPath('data.result.availability', 'unavailable')->assertJsonPath('data.result.reason', 'MISSING_COMPONENT');
    }

    public function test_correction_invalidates_only_dependent_samples_and_preserves_history(): void
    {
        $s = $this->scenario();
        $a = $this->observation($s, '2', 1);
        $b = $this->observation($s, '4', 2);
        $calculation = $this->requestCalculation($s, [$a, $b]);
        $independent = $this->requestCalculation($s, [$b]);
        $this->process($s, $calculation);
        $this->process($s, $independent);
        $this->correct($s, $a, '10');
        $stale = $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.status', 'stale')->assertJsonPath('data.result', null)->json('data');
        $this->getJson($s['calculations'].'/'.$independent['id'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.result.value', '4');
        $this->process($s, $calculation);
        $this->process($s, $stale);
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.result.value', '14')->assertJsonPath('data.number', 2);
        $this->getJson($s['calculations'].'/'.$calculation['id'].'/history')->assertOk()->assertJsonPath('data.1.result.value', '6');
        Queue::assertPushed(RecalculateObservationStatistics::class, fn ($job) => $job->observationId === $a['id']);
    }

    public function test_real_queue_runs_the_initial_calculation_and_automatic_recalculation(): void
    {
        $s = $this->scenario('sum', false);
        config(['queue.default' => 'sync']);
        $a = $this->observation($s, '2', 1);
        $calculation = $this->requestCalculation($s, [$a]);
        $this->assertSame('2', $calculation['result']['value']);
        $this->correct($s, $a, '8');
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.result.value', '8')->assertJsonPath('data.number', 2);
    }

    public function test_revoked_permissions_stop_worker_and_other_actors_cannot_read_private_results(): void
    {
        $s = $this->scenario();
        $calculation = $this->requestCalculation($s, [$this->observation($s, '2', 1)]);
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertNotFound();
        $this->postJson($s['calculations'], ['metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'], 'observations' => [], 'confirm' => true])->assertForbidden();
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->expectException(AuthorizationException::class);
        $this->process($s, $calculation);
    }

    public function test_cross_tenant_ids_and_mixed_contexts_are_refused_without_writing_a_calculation(): void
    {
        $s = $this->scenario();
        $a = $this->observation($s, '2', 1);
        $other = Person::factory()->create(['organization_id' => $s['org']->id]);
        $b = $this->observation($s, '4', 2, null, ['person_id' => $other->id]);
        $this->postJson($s['calculations'], ['metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'], 'observations' => [$a['id'], $b['id']], 'confirm' => true])->assertUnprocessable()->assertJsonValidationErrors('observations');
        $this->postJson($s['calculations'], ['metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'], 'observations' => [(string) Str::uuid()], 'confirm' => true])->assertNotFound();
        $this->assertDatabaseCount('statistical_calculations', 0);
    }

    public function test_closed_inputs_empty_duplicates_confirmation_and_pause_flag_are_enforced(): void
    {
        $s = $this->scenario();
        $a = $this->observation($s, '2', 1);
        $body = ['metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'], 'observations' => [$a['id']], 'confirm' => true];
        $this->postJson($s['calculations'], [...$body, 'organization_id' => $s['org']->id])->assertUnprocessable();
        $this->postJson($s['calculations'], [...$body, 'observations' => []])->assertUnprocessable();
        $this->postJson($s['calculations'], [...$body, 'observations' => [$a['id'], $a['id']]])->assertUnprocessable();
        $this->postJson($s['calculations'], [...$body, 'confirm' => false])->assertUnprocessable();
        config(['statistical_calculations.processing_enabled' => false]);
        $this->postJson($s['calculations'], $body)->assertConflict()->assertJsonPath('code', 'STAT_CALCULATION_DISABLED');
        $this->assertDatabaseCount('statistical_calculations', 0);
    }

    public function test_recalculation_checks_generation_and_old_failure_callback_cannot_poison_new_work(): void
    {
        $s = $this->scenario();
        $calculation = $this->requestCalculation($s, [$this->observation($s, '2', 1)]);
        app(StatisticalCalculationProcessor::class)->failed($s['org']->id, $s['writer']->id, $calculation['id'], $calculation['version']);
        $next = $this->postJson($s['calculations'].'/'.$calculation['id'].'/recalculate', ['version' => $calculation['version'], 'confirm' => true])->assertOk()->json('data');
        app(StatisticalCalculationProcessor::class)->failed($s['org']->id, $s['writer']->id, $calculation['id'], $calculation['version']);
        $this->postJson($s['calculations'].'/'.$calculation['id'].'/recalculate', ['version' => $calculation['version'], 'confirm' => true])->assertConflict();
        $this->process($s, $next);
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.result.value', '2');
    }

    public function test_failure_after_result_insertion_rolls_back_then_retry_creates_one_revision(): void
    {
        $s = $this->scenario();
        $calculation = $this->requestCalculation($s, [$this->observation($s, '2', 1)]);
        StatisticalCalculationRevision::created(fn () => throw new \RuntimeException('Injected calculation write failure'));
        try {
            $this->process($s, $calculation);
            $this->fail('The injected write must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected calculation write failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.created: '.StatisticalCalculationRevision::class);
        }
        $this->assertDatabaseCount('statistical_calculation_revisions', 0);
        $this->process($s, $calculation);
        $this->assertDatabaseCount('statistical_calculation_revisions', 1);
    }

    public function test_new_schema_catalog_and_immutable_history_preserve_private_exports(): void
    {
        $s = $this->scenario();
        $calculation = $this->requestCalculation($s, [$this->observation($s, '2', 1)]);
        $this->process($s, $calculation);
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $catalog = app(PortabilityRepository::class);
        $catalog->assertCatalog();
        $backup = iterator_to_array($catalog->rows($s['org']->id, 100));
        $this->assertStringNotContainsString('Grille privée', json_encode($backup, JSON_THROW_ON_ERROR));
        foreach (['statistical_calculation_revisions', 'statistical_calculation_sources'] as $table) {
            try {
                DB::transaction(fn () => DB::table($table)->delete());
                $this->fail('History must be immutable.');
            } catch (QueryException) {
                $this->assertDatabaseCount($table, 1);
            }
        }
        $this->expectException(\RuntimeException::class);
        (require database_path('migrations/2026_10_19_183908_create_statistical_calculations.php'))->down();
    }

    public function test_match_audience_is_rechecked_for_detail_list_and_history(): void
    {
        $s = $this->scenario();
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id,
            'kind' => 'scrim', 'visibility' => 'organization', 'created_by' => $s['reader']->id,
            'sharing_authorized_by' => $s['reader']->id, 'sharing_authorized_at' => now()]);
        $row = $this->observation($s, '2', 1, null, ['source_kind' => 'scrim', 'match_id' => $match->id]);
        $calculation = $this->requestCalculation($s, [$row]);
        $this->process($s, $calculation);
        $match->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertNotFound();
        $this->getJson($s['calculations'].'/'.$calculation['id'].'/history')->assertNotFound();
        $this->getJson($s['calculations'])->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_tenant_confirmation_mfa_and_foreign_tenant_boundaries_are_enforced(): void
    {
        $s = $this->scenario();
        $calculation = $this->requestCalculation($s, [$this->observation($s, '2', 1)]);
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.write', 'sports.statistics.read', 'sports.statistics.write']);
        $this->getJson('/api/v1/tenants/'.$other->id.'/statistical-calculations/'.$calculation['id'])->assertNotFound();
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => [
            'organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader(ActiveOrganizationSession::REVISION_HEADER, $revision);
        $this->postJson($s['calculations'].'/'.$calculation['id'].'/recalculate', ['version' => $calculation['version'], 'confirm' => true])->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->getJson($s['calculations'])->assertForbidden();
    }

    public function test_recipe_rejects_duplicate_non_additive_and_incompatible_scope_components(): void
    {
        $s = $this->scenario();
        $denominator = $this->metric($s, 'DENOMINATOR');
        $manual = $this->metric($s, 'MANUAL', 'manual');
        $ratio = $this->metric($s, 'RATIO', 'ratio', ['numerator' => [$s['metric']['id']], 'denominator' => [$denominator['id']]]);
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->json('meta');
        $body = array_intersect_key([...$ratio, 'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'],
            'confirm_global' => true, 'reason' => 'Vérification de la recette de ratio déclarée.'], MetricCatalogueInput::rules('update'));
        foreach ([['numerator' => [$s['metric']['id']], 'denominator' => [$s['metric']['id']]],
            ['numerator' => [$manual['id']], 'denominator' => [$denominator['id']]],
            ['numerator' => [$s['metric']['id']], 'denominator' => [$denominator['id']], 'expression' => 'eval()']] as $recipe) {
            $this->patchJson('/api/v1/metric-catalogue/'.$ratio['id'], [...$body, 'ratio_components' => $recipe])->assertUnprocessable()->assertJsonValidationErrors('ratio_components');
        }
        unset($body['ratio_components']);
        $this->patchJson('/api/v1/metric-catalogue/'.$ratio['id'], [...$body, 'aggregation_level' => 'match'])->assertUnprocessable()->assertJsonValidationErrors('ratio_components');
    }

    public function test_a_paused_pipeline_still_invalidates_old_results_without_creating_a_new_one(): void
    {
        $s = $this->scenario();
        $row = $this->observation($s, '2', 1);
        $calculation = $this->requestCalculation($s, [$row]);
        $this->process($s, $calculation);
        config(['statistical_calculations.processing_enabled' => false]);
        $this->correct($s, $row, '8');
        $this->getJson($s['calculations'].'/'.$calculation['id'])->assertOk()->assertJsonPath('data.result', null)->assertJsonPath('data.status', 'stale');
        $this->assertDatabaseCount('statistical_calculation_revisions', 1);
        Queue::assertNotPushed(RecalculateObservationStatistics::class);
    }

    public function test_dispatch_failure_keeps_a_durable_calculation_for_explicit_resume(): void
    {
        $s = $this->scenario();
        $row = $this->observation($s, '2', 1);
        $this->mock(StatisticalCalculationQueue::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Private transport detail'));
        $response = $this->postJson($s['calculations'], ['metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'],
            'observations' => [$row['id']], 'confirm' => true])->assertConflict()->assertJsonPath('code', 'STAT_CALCULATION_DISPATCH_FAILED');
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Private transport detail', $body);
        $this->assertDatabaseCount('statistical_calculations', 1);
        $this->assertDatabaseCount('statistical_calculation_revisions', 0);
    }

    public function test_lists_sources_and_history_are_bounded_and_use_constant_list_query_counts(): void
    {
        $s = $this->scenario();
        $rows = array_map(fn ($day) => $this->observation($s, (string) $day, $day), range(1, 11));
        $calculation = $this->requestCalculation($s, $rows);
        $this->process($s, $calculation);
        $this->getJson($s['calculations'].'/'.$calculation['id'].'?page=2')->assertOk()->assertJsonCount(1, 'data.sources.data')->assertJsonPath('data.sources.meta.total', 11);
        $this->getJson($s['calculations'].'?page=0')->assertUnprocessable();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($s['calculations'])->assertOk();
        $count = count(DB::getQueryLog());
        for ($n = 1; $n <= 10; $n++) {
            $this->requestCalculation($s, array_slice($rows, 0, $n));
        }
        DB::flushQueryLog();
        $this->getJson($s['calculations'])->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 11);
        $this->assertSame($count, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }
}
