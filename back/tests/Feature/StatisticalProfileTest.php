<?php

namespace Tests\Feature;

use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Performance\Application\Validation\MetricCatalogueInput;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMetricCatalogue;
use Tests\Fixtures\ProvidesObservations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class StatisticalProfileTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMetricCatalogue, ProvidesObservations, ProvidesPeopleAccess;

    /** @return array<string,mixed> */
    private function scenario(): array
    {
        $s = $this->observationScenario();
        $metric = $this->metric($s, 'COUNT');

        return [...$s, 'metric' => $metric, 'profiles' => '/api/v1/tenants/'.$s['org']->id.'/statistical-profiles'];
    }

    /** @param array<string,mixed> $s
     * @param array<string,list<string>>|null $recipe
     * @return array<string,mixed> */
    private function metric(array $s, string $code, string $aggregation = 'sum', ?array $recipe = null): array
    {
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->assertOk()->json('meta');
        $input = [...$s['metric'], 'game_id' => $s['game']->id, 'code' => $code, 'aggregation' => $aggregation,
            'ratio_components' => $recipe, 'zero_denominator' => $aggregation === 'ratio' ? 'zero' : 'not_applicable',
            'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version'],
            'confirm_global' => true, 'reason' => 'Définition vérifiée pour les profils statistiques.'];
        $result = $this->postJson('/api/v1/metric-catalogue', array_intersect_key($input, MetricCatalogueInput::rules('store')))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['writer']);

        return $result;
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $extra
     * @return array<string,mixed> */
    private function observation(array $s, string $value, int $day, array $extra = []): array
    {
        $date = '2026-10-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT);

        return $this->postJson($s['root'], [...$s['input'], 'metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'],
            'value' => $value, 'period_start' => $date, 'period_end' => $date, ...$extra])->assertCreated()->json('data');
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $extra */
    private function url(array $s, array $extra = []): string
    {
        return $s['profiles'].'?'.http_build_query(['metric_id' => $s['metric']['id'], 'metric_version' => $s['metric']['version'],
            'people' => [$s['person']->id], 'from' => '2026-10-01', 'to' => '2026-10-18', ...$extra]);
    }

    public function test_reader_gets_a_live_versioned_profile_without_creating_jobs_or_records(): void
    {
        $s = $this->scenario();
        $a = $this->observation($s, '0.1', 1);
        $this->observation($s, '0.2', 2);
        $this->actingAsWithMfa($s['reader']);
        Queue::fake();
        $response = $this->getJson($this->url($s))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.live', true)->assertJsonPath('data.profiles.0.source_count', 2)
            ->assertJsonPath('data.profiles.0.groups.data.0.result.value', '0.3')
            ->assertJsonPath('data.profiles.0.groups.data.0.result.sample_size', 6)
            ->assertJsonPath('data.profiles.0.latest_observed_on', '2026-10-19')->assertJsonPath('data.comparison', null);
        $this->assertNotNull($response->json('data.profiles.0.last_updated_at'));
        $this->assertNotNull($response->json('meta.request_id'));
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Note privée', $body);
        $this->assertSame($a['metric_version'], $response->json('data.metric.version'));
        $this->assertDatabaseCount('statistical_calculations', 0);
        $this->assertDatabaseCount('observation_revisions', 2);
        Queue::assertNothingPushed();
    }

    public function test_filters_use_exact_context_and_never_mix_sources_or_overlapping_windows(): void
    {
        $s = $this->scenario();
        $role = GameCatalogueEntry::factory()->create(['kind' => 'role', 'game_id' => $s['game']->id, 'scope_key' => $s['game']->id, 'definition' => null]);
        $release = GameCatalogueEntry::factory()->create(['kind' => 'release', 'game_id' => $s['game']->id, 'scope_key' => $s['game']->id, 'definition' => null]);
        $filters = ['role_id' => $role->id, 'release_id' => $release->id, 'mode_id' => $s['mode']->id,
            'season' => '2026', 'region' => 'Europe', 'map' => '<script>Map</script>', 'opponent' => 'Adversaire', 'source_label' => 'Source A'];
        $this->observation($s, '2', 1, $filters);
        $this->observation($s, '90', 2, [...$filters, 'source_label' => 'Source B']);
        $this->observation($s, '80', 3, [...$filters, 'season' => '2025']);
        $this->getJson($this->url($s, $filters))->assertOk()->assertJsonPath('data.profiles.0.source_count', 1)
            ->assertJsonPath('data.profiles.0.groups.data.0.result.value', '2');
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.groups.meta.total', 3);
        $this->getJson($this->url($s, ['from' => '2026-10-02', 'to' => '2026-10-02']))->assertOk()->assertJsonPath('data.profiles.0.source_count', 1);
        $this->getJson($this->url($s, ['source_label' => "' OR 1=1 --"]))->assertOk()->assertJsonPath('data.profiles.0.source_count', 0);
    }

    public function test_comparison_shows_exact_difference_and_distinguishes_sample_sizes(): void
    {
        $s = $this->scenario();
        $second = Person::factory()->create(['organization_id' => $s['org']->id]);
        $this->observation($s, '2', 1);
        $this->observation($s, '1', 1, ['person_id' => $second->id, 'sample_size' => 5]);
        $this->getJson($this->url($s, ['people' => [$s['person']->id, $second->id]]))->assertOk()
            ->assertJsonPath('data.comparison.data.0.comparable', true)->assertJsonPath('data.comparison.data.0.difference', '1')
            ->assertJsonPath('data.comparison.data.0.unequal_samples', true);
    }

    public function test_different_periods_and_contexts_never_produce_a_comparison_difference(): void
    {
        $s = $this->scenario();
        $second = Person::factory()->create(['organization_id' => $s['org']->id]);
        $this->observation($s, '2', 1);
        $this->observation($s, '1', 2, ['person_id' => $second->id]);
        $url = $this->url($s, ['people' => [$s['person']->id, $second->id]]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.comparison.data.0.reason', 'PERIOD_MISMATCH')
            ->assertJsonPath('data.comparison.data.0.difference', null);
        $this->observation($s, '10', 1, ['person_id' => $second->id, 'season' => '2025']);
        $response = $this->getJson($url)->assertOk();
        $this->assertContains('MISSING_CONTEXT', array_column($response->json('data.comparison.data'), 'reason'));
    }

    public function test_corrections_are_visible_on_next_read_and_missing_or_voided_values_are_explicit(): void
    {
        $s = $this->scenario();
        $row = $this->observation($s, '0', 1);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.groups.data.0.result.value', '0');
        $this->travel(1)->hours();
        $this->patchJson($s['root'].'/'.$row['id'], ['version' => $row['version'], 'availability' => 'unavailable', 'value' => null,
            'missing_reason' => 'Source non disponible.', 'notes' => null, 'status' => 'active', 'reason' => 'Correction motivée de la disponibilité.'])->assertOk();
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.groups.data.0.result.value', null)
            ->assertJsonPath('data.profiles.0.groups.data.0.result.missing_count', 1)
            ->assertJsonPath('data.profiles.0.groups.data.0.result.incomplete', true)
            ->assertJsonPath('data.profiles.0.last_updated_at', '2026-10-19T11:00:00.000000Z');
    }

    public function test_ambiguous_duplicate_origins_are_flagged_without_publishing_an_aggregate(): void
    {
        $s = $this->scenario();
        $this->observation($s, '1', 1);
        $this->observation($s, '2', 1);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.groups.data.0.result', null)
            ->assertJsonPath('data.profiles.0.groups.data.0.issue', 'INCOMPATIBLE_SOURCES');
    }

    public function test_ratio_profiles_combine_pinned_components_and_never_invent_a_zero(): void
    {
        $s = $this->scenario();
        $denominator = $this->metric($s, 'DENOMINATOR');
        $ratio = $this->metric($s, 'RATIO', 'ratio', ['numerator' => [$s['metric']['id']], 'denominator' => [$denominator['id']]]);
        $this->observation($s, '1', 1);
        $this->observation($s, '1', 1, ['metric_id' => $denominator['id'], 'metric_version' => $denominator['version']]);
        $this->observation($s, '1', 2);
        $this->observation($s, '9', 2, ['metric_id' => $denominator['id'], 'metric_version' => $denominator['version']]);
        $url = $this->url($s, ['metric_id' => $ratio['id'], 'metric_version' => $ratio['version']]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.profiles.0.groups.data.0.result.value', '0.2');
        $this->observation($s, '1', 3);
        $this->getJson($url)->assertOk()->assertJsonPath('data.profiles.0.groups.data.0.result.value', null)
            ->assertJsonPath('data.profiles.0.groups.data.0.result.reason', 'MISSING_COMPONENT');
    }

    public function test_profiles_and_timelines_are_paginated_and_empty_results_are_explicit(): void
    {
        $s = $this->scenario();
        for ($day = 1; $day <= 11; $day++) {
            $this->observation($s, (string) $day, $day, ['source_label' => 'Source '.$day]);
        }
        $this->getJson($this->url($s))->assertOk()->assertJsonCount(10, 'data.profiles.0.groups.data')->assertJsonCount(10, 'data.profiles.0.timeline.data');
        $this->getJson($this->url($s, ['page' => 2, 'timeline_page' => 2]))->assertOk()
            ->assertJsonCount(1, 'data.profiles.0.groups.data')->assertJsonCount(1, 'data.profiles.0.timeline.data');
        $this->getJson($this->url($s, ['page' => 9999]))->assertOk()->assertJsonPath('data.profiles.0.groups.meta.current_page', 2)
            ->assertJsonCount(1, 'data.profiles.0.groups.data');
        $this->getJson($this->url($s, ['season' => 'inexistante']))->assertOk()->assertJsonCount(0, 'data.profiles.0.groups.data')
            ->assertJsonPath('data.profiles.0.latest_observed_on', null);
    }

    public function test_choices_are_read_only_tenant_scoped_and_do_not_expose_private_matches(): void
    {
        $s = $this->scenario();
        $match = MatchRecord::factory()->create(['organization_id' => $s['org']->id, 'game_id' => $s['game']->id,
            'kind' => 'scrim', 'visibility' => 'organization', 'created_by' => $s['writer']->id,
            'sharing_authorized_by' => $s['writer']->id, 'sharing_authorized_at' => now()]);
        $this->observation($s, '2', 1, ['source_kind' => 'scrim', 'match_id' => $match->id]);
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($s['profiles'].'/people')->assertOk()->assertJsonCount(1, 'data');
        $match->forceFill(['visibility' => 'private', 'sharing_authorized_by' => null, 'sharing_authorized_at' => null, 'version' => (string) Str::uuid()])->save();
        $this->getJson($s['profiles'].'/people')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.source_count', 0);
    }

    public function test_foreign_people_and_metric_versions_are_not_disclosed(): void
    {
        $s = $this->scenario();
        $person = Person::factory()->create();
        $this->getJson($this->url($s, ['people' => [$person->id]]))->assertNotFound();
        $this->getJson($this->url($s, ['metric_version' => (string) Str::uuid()]))->assertNotFound();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.statistics.read']);
        $this->getJson(str_replace($s['org']->id, $other->id, $this->url($s)))->assertNotFound();
    }

    public function test_permissions_mfa_and_closed_input_boundaries_are_enforced(): void
    {
        $s = $this->scenario();
        $this->getJson($s['profiles'])->assertUnprocessable();
        foreach ([['people' => [$s['person']->id, strtoupper($s['person']->id)]], ['page' => 0], ['from' => '2025-01-01'], ['to' => '2026-09-01'], ['sort' => 'value'], ['season' => ['invalid']]] as $invalid) {
            $this->getJson($this->url($s, $invalid))->assertUnprocessable();
        }
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->getJson($this->url($s))->assertForbidden();
        $this->actingAsWithMfa($s['reader']);
        $this->withSession([MfaSession::PROOF_KEY => null])->getJson($this->url($s))->assertForbidden();
    }

    public function test_more_than_the_candidate_limit_is_refused_before_encrypted_filtering(): void
    {
        $s = $this->scenario();
        $prototype = StatisticalObservation::query()->whereKey($this->observation($s, '1', 1)['id'])->firstOrFail();
        $rows = [];
        for ($index = 0; $index < 1000; $index++) {
            $rows[] = [...$prototype->getAttributes(), 'id' => (string) Str::uuid()];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            StatisticalObservation::query()->insert($chunk);
        }
        $this->getJson($this->url($s, ['season' => 'no match']))->assertConflict()->assertJsonPath('code', 'STAT_PROFILE_LIMIT');
    }

    public function test_profile_query_count_does_not_grow_with_observations(): void
    {
        $s = $this->scenario();
        $this->observation($s, '1', 1);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson($this->url($s))->assertOk();
        $queries = count(DB::getQueryLog());
        $this->observation($s, '2', 2);
        DB::flushQueryLog();
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.source_count', 2);
        $this->assertSame($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_incomplete_and_voided_samples_cannot_produce_a_comparison(): void
    {
        $s = $this->scenario();
        $other = Person::factory()->create(['organization_id' => $s['org']->id]);
        $row = $this->observation($s, '2', 1);
        $this->observation($s, '3', 1, ['person_id' => $other->id]);
        $this->patchJson($s['root'].'/'.$row['id'], ['version' => $row['version'], 'availability' => 'available', 'value' => '2',
            'missing_reason' => null, 'notes' => null, 'status' => 'voided', 'reason' => 'Invalidation motivée de cette observation.'])->assertOk();
        $this->getJson($this->url($s, ['people' => [$s['person']->id, $other->id]]))->assertOk()
            ->assertJsonPath('data.comparison.data.0.comparable', false)->assertJsonPath('data.comparison.data.0.reason', 'INCOMPLETE_SAMPLE')
            ->assertJsonPath('data.comparison.data.0.difference', null)->assertJsonPath('data.profiles.0.groups.data.0.result.voided_count', 1)
            ->assertJsonPath('data.profiles.0.timeline.data.0.status', 'voided');
    }

    public function test_historical_definition_and_partial_periods_are_not_silently_rebased_or_clipped(): void
    {
        $s = $this->scenario();
        $this->observation($s, '2', 2, ['period_start' => '2026-10-01']);
        $this->actingAsWithMfa($s['operator']);
        $meta = $this->getJson('/api/v1/metric-catalogue')->json('meta');
        $new = $this->patchJson('/api/v1/metric-catalogue/'.$s['metric']['id'], array_intersect_key([
            ...$s['metric'], 'title' => 'Définition actualisée', 'confirm_global' => true, 'reason' => 'Correction documentaire de la définition.',
            'catalogue_version' => $meta['catalogue_version'], 'game_catalogue_version' => $meta['game_catalogue_version']],
            MetricCatalogueInput::rules('update')))->assertOk()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.profiles.0.groups.data.0.result.value', '2');
        $this->getJson($this->url($s, ['metric_version' => $new['version']]))->assertOk()->assertJsonPath('data.profiles.0.source_count', 0);
        $this->getJson($this->url($s, ['from' => '2026-10-02']))->assertOk()->assertJsonPath('data.profiles.0.source_count', 0);
    }

    public function test_manual_metrics_and_documentary_ratios_keep_their_timeline_without_fabricating_results(): void
    {
        $s = $this->scenario();
        $manual = $this->metric($s, 'MANUAL', 'manual');
        $ratio = $this->metric($s, 'DOCUMENTARY', 'ratio');
        $this->observation($s, '9', 1, ['metric_id' => $manual['id'], 'metric_version' => $manual['version']]);
        $this->observation($s, '4', 1, ['metric_id' => $ratio['id'], 'metric_version' => $ratio['version']]);
        $this->getJson($this->url($s, ['metric_id' => $manual['id'], 'metric_version' => $manual['version']]))->assertOk()
            ->assertJsonPath('data.profiles.0.groups.data.0.result.reason', 'MANUAL_AGGREGATION')->assertJsonPath('data.profiles.0.timeline.data.0.value', '9');
        $this->getJson($this->url($s, ['metric_id' => $ratio['id'], 'metric_version' => $ratio['version']]))->assertOk()
            ->assertJsonPath('data.profiles.0.groups.data.0.issue', 'RATIO_COMPONENTS_REQUIRED')->assertJsonPath('data.profiles.0.timeline.data.0.value', '4');
    }

    public function test_anonymous_or_sports_only_accounts_cannot_consult_profiles_or_choices(): void
    {
        $s = $this->scenario();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $user, ['sports.read']);
        $this->actingAsWithMfa($user);
        $this->getJson($this->url($s))->assertForbidden();
        $this->getJson($s['profiles'].'/people')->assertForbidden();
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->getJson($this->url($s))->assertUnauthorized();
        $this->getJson($s['profiles'].'/people')->assertUnauthorized();
    }

    public function test_equal_period_bounds_do_not_hide_different_covered_intervals(): void
    {
        $s = $this->scenario();
        $other = Person::factory()->create(['organization_id' => $s['org']->id]);
        foreach ([1, 3] as $day) {
            $this->observation($s, '2', $day);
            $this->observation($s, '2', $day, ['person_id' => $other->id]);
        }
        $this->observation($s, '1', 2, ['person_id' => $other->id]);
        $this->getJson($this->url($s, ['people' => [$s['person']->id, $other->id]]))->assertOk()
            ->assertJsonPath('data.comparison.data.0.left.period_start', '2026-10-01')
            ->assertJsonPath('data.comparison.data.0.right.period_start', '2026-10-01')
            ->assertJsonPath('data.comparison.data.0.left.period_end', '2026-10-03')
            ->assertJsonPath('data.comparison.data.0.right.period_end', '2026-10-03')
            ->assertJsonPath('data.comparison.data.0.reason', 'PERIOD_MISMATCH')->assertJsonPath('data.comparison.data.0.difference', null);
    }
}
