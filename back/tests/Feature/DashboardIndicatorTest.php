<?php

namespace Tests\Feature;

use App\Architecture\Database\TenantSchemaInspector;
use App\Modules\Competition\Domain\Contracts\CompetitionRegistrationRepository;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaign;
use App\Modules\Partnerships\Domain\Models\SponsorshipDeliverable;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Reporting\Application\Contracts\DashboardIndicatorQueue;
use App\Modules\Reporting\Application\Services\DashboardIndicatorProcessor;
use App\Modules\Reporting\Application\Services\DashboardIndicatorService;
use App\Modules\Reporting\Domain\Contracts\DashboardIndicatorRepository;
use App\Modules\Reporting\Infrastructure\Queue\CalculateDashboardIndicator;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class DashboardIndicatorTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesPeopleAccess,\Tests\Fixtures\ProvidesContractDocuments;

    /** @param list<string> $permissions
     * @return array<string,mixed> */
    private function scenario(array $permissions = ['reporting.direction', 'sports.read']): array
    {
        $this->travelTo('2026-10-20 10:00:00 UTC');
        config(['dashboard_indicators.processing_enabled' => true, 'queue.default' => 'database']);
        $org = Organization::factory()->create(['timezone' => 'UTC']);
        $user = User::factory()->withMfa()->create();
        $assignment = $this->grantPeopleAccess($org, $user, $permissions);
        $this->actingAsWithMfa($user);
        Queue::fake([CalculateDashboardIndicator::class]);

        return compact('org', 'user', 'assignment') + ['root' => '/api/v1/tenants/'.$org->id.'/dashboards/active_people/indicator',
            'input' => ['view' => 'direction', 'from' => '2026-10-20', 'to' => '2026-10-25']];
    }

    /** @param array<string,mixed> $s */
    private function url(array $s): string
    {
        return $s['root'].'?'.http_build_query($s['input']);
    }

    /** @param array<string,mixed> $s
     * @return array<string,mixed> */
    private function requestIndicator(array $s): array
    {
        return $this->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertOk()->json('data');
    }

    /** @param array<string,mixed> $s
     * @param array<string,mixed> $row */
    private function process(array $s, array $row): void
    {
        app(DashboardIndicatorProcessor::class)->process($this->personContext($s['org'], $s['user']), $row['id'], $row['version']);
    }

    public function test_reads_never_calculate_or_queue_and_request_is_idempotent_and_tenant_bound(): void
    {
        $s = $this->scenario();
        Person::factory()->create(['organization_id' => $s['org']->id]);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'missing')->assertJsonPath('data.result', null)->assertHeader('Cache-Control', 'no-store, private');
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('dashboard_indicators', 0);
        $row = $this->requestIndicator($s);
        $again = $this->requestIndicator($s);
        $this->assertSame($row['id'], $again['id']);
        $this->assertDatabaseCount('dashboard_indicators', 1);
        $this->assertDatabaseCount('dashboard_indicator_revisions', 0);
        Queue::assertPushed(CalculateDashboardIndicator::class, 1);
        Queue::assertPushed(CalculateDashboardIndicator::class, fn ($job) => $job->context->tenantId === $s['org']->id && $job->context->actorUserId === $s['user']->id && $job->afterCommit && $job->queue === 'reports');
        $raw = DB::table('dashboard_indicators')->firstOrFail();
        $this->assertStringContainsString($s['org']->id, $raw->cache_key);
    }

    public function test_worker_persists_versioned_counts_without_private_fields_and_replay_is_noop(): void
    {
        $s = $this->scenario();
        Person::factory()->count(3)->create(['organization_id' => $s['org']->id, 'status' => 'active', 'pseudonym' => 'Secret label']);
        Person::factory()->create(['status' => 'active']);
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        $this->process($s, $row);
        $response = $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'fresh')->assertJsonPath('data.result.total', 3)->assertJsonPath('data.result.groups.0.count', 3)->assertJsonPath('data.revision', 1);
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString('Secret label', $body);
        $this->assertDatabaseCount('dashboard_indicator_revisions', 1);
        $this->assertNotSame('{"total":3}', DB::table('dashboard_indicator_revisions')->value('result'));
    }

    public function test_zero_is_fresh_and_expiry_hides_old_result_until_targeted_recalculation(): void
    {
        $s = $this->scenario();
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.result.total', 0);
        $this->travel(301)->seconds();
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'stale')->assertJsonPath('data.result', null);
        $this->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertConflict()->assertJsonPath('code', 'DASHBOARD_INDICATOR_STALE');
        $next = $this->postJson($s['root'], [...$s['input'], 'confirm' => true, 'version' => $row['version']])->assertOk()->json('data');
        $this->process($s, $row);
        $this->assertDatabaseCount('dashboard_indicator_revisions', 1);
        $this->process($s, $next);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.result.total', 0);
    }

    public function test_bulk_source_changes_invalidate_immediately_and_rollback_preserves_freshness(): void
    {
        $s = $this->scenario();
        $person = Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active']);
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        DB::beginTransaction();
        DB::table('people')->where('id', $person->id)->update(['status' => 'former']);
        DB::rollBack();
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'fresh');
        DB::table('people')->where('id', $person->id)->update(['status' => 'former']);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'stale')->assertJsonPath('data.result', null);
        Queue::assertPushed(CalculateDashboardIndicator::class, 1);
    }

    public function test_source_invalidation_isolated_by_card_and_tenant(): void
    {
        $s = $this->scenario();
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        Person::factory()->create();
        PersonImport::factory()->create(['organization_id' => $s['org']->id]);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'fresh');
    }

    public function test_worker_and_reads_recheck_permissions_and_actor_isolation(): void
    {
        $s = $this->scenario();
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $other, ['reporting.direction', 'sports.read']);
        $this->actingAsWithMfa($other);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'missing')->assertJsonPath('data.result', null);
        $this->actingAsWithMfa($s['user']);
        $s['assignment']->forceFill(['status' => 'revoked'])->save();
        $this->getJson($this->url($s))->assertForbidden();
        $this->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertForbidden();
        $this->expectException(AuthorizationException::class);
        $this->process($s, $row);
    }

    public function test_disabled_and_synchronous_processing_refuse_requests_without_dispatch(): void
    {
        $s = $this->scenario();
        config(['dashboard_indicators.processing_enabled' => false]);
        $this->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertConflict()->assertJsonPath('code', 'DASHBOARD_INDICATOR_DISABLED');
        config(['dashboard_indicators.processing_enabled' => true, 'queue.default' => 'sync']);
        $this->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertConflict();
        Queue::assertNothingPushed();
        $this->assertDatabaseCount('dashboard_indicators', 0);
    }

    public function test_validation_confirmation_and_unsupported_audience_cards_are_closed(): void
    {
        $s = $this->scenario(['reporting.direction', 'reporting.manager', 'sports.read']);
        $this->postJson($s['root'], $s['input'])->assertUnprocessable();
        $this->postJson($s['root'], [...$s['input'], 'confirm' => true, 'actor_user_id' => 42])->assertUnprocessable();
        $this->getJson($s['root'].'?view=direction&from=2026-09-01&to=2026-10-25')->assertUnprocessable();
        $this->getJson(str_replace('active_people', 'matches', $this->url($s)))->assertNotFound();
        $other = Organization::factory()->create();
        $this->getJson(str_replace($s['org']->id, $other->id, $this->url($s)))->assertNotFound();
    }

    public function test_history_is_immutable_tenant_schema_is_safe_and_failure_cannot_poison_new_generation(): void
    {
        $s = $this->scenario();
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        app(DashboardIndicatorService::class)->fail($s['org']->id, $s['user']->id, $row['id'], $row['version']);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'fresh');
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
        $this->expectException(QueryException::class);
        DB::table('dashboard_indicator_revisions')->update(['source_version' => 999]);
    }

    public function test_worker_never_publishes_a_result_if_source_changes_during_the_aggregation(): void
    {
        $s = $this->scenario();
        $person = Person::factory()->create(['organization_id' => $s['org']->id, 'status' => 'active']);
        $row = $this->requestIndicator($s);
        $changed = false;
        DB::listen(function (QueryExecuted $event) use (&$changed, $person): void {
            if (! $changed && str_contains(strtolower($event->sql), 'count(') && str_contains($event->sql, 'people')) {
                $changed = true;
                DB::table('people')->where('id', $person->id)->update(['status' => 'former']);
            }
        });
        $this->process($s, $row);
        $this->assertTrue($changed);
        $this->assertDatabaseCount('dashboard_indicator_revisions', 0);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.result', null);
    }

    public function test_queue_transport_failure_is_recoverable_and_does_not_leave_a_false_result(): void
    {
        $s = $this->scenario();
        $this->mock(DashboardIndicatorQueue::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Private transport details'));
        $this->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertConflict()->assertJsonPath('code', 'DASHBOARD_INDICATOR_DISPATCH_FAILED');
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'failed')->assertJsonPath('data.result', null);
    }

    public function test_organization_source_counts_match_import_quality_and_sponsor_filters(): void
    {
        $s = $this->scenario(['reporting.direction', 'reporting.administration', 'sports.read', 'sports.write', 'administrative.read', 'administrative.write', 'partnerships.read']);
        Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => null]);
        Person::factory()->create(['organization_id' => $s['org']->id, 'user_id' => $s['user']->id]);
        PersonImport::factory()->create(['organization_id' => $s['org']->id]);
        $sponsor = Sponsor::factory()->create(['organization_id' => $s['org']->id]);
        $agreement = SponsorshipAgreement::factory()->create(['sponsor_id' => $sponsor->id]);
        $campaign = SponsorshipCampaign::factory()->create(['agreement_id' => $agreement->id]);
        SponsorshipDeliverable::factory()->create(['campaign_id' => $campaign->id, 'due_at' => now()->subHour()]);
        SponsorshipDeliverable::factory()->create(['campaign_id' => $campaign->id, 'due_at' => now()->addDay()]);
        foreach (['unlinked_people' => 'administration', 'people_imports' => 'administration', 'overdue_deliverables' => 'direction'] as $card => $view) {
            $target = [...$s, 'root' => str_replace('active_people', $card, $s['root']), 'input' => [...$s['input'], 'view' => $view]];
            $row = $this->requestIndicator($target);
            $this->process($target, $row);
            $this->getJson($this->url($target))->assertOk()->assertJsonPath('data.result.total', 1);
        }
    }

    public function test_registration_count_uses_only_latest_persisted_deadline_and_is_invalidated_by_new_revision(): void
    {
        $s = $this->scenario(['reporting.manager', 'sports.read', 'sports.competitions.read', 'sports.registrations.read']);
        $s['root'] = str_replace('active_people', 'registration_due', $s['root']);
        $s['input']['view'] = 'manager';
        $competition = ExternalCompetition::factory()->create(['organization_id' => $s['org']->id]);
        $records = app(CompetitionRegistrationRepository::class);
        $attributes = ['id' => (string) Str::uuid(), 'organization_id' => $s['org']->id, 'competition_id' => $competition->id, 'number' => 1, 'payload' => [],
            'reason' => 'Échéance contrôlée', 'actor_user_id' => $s['user']->id, 'next_due_at' => '2026-10-25 23:30:00', 'checked_at' => null];
        $records->append($attributes);
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.result.total', 1);
        $records->append([...$attributes, 'id' => (string) Str::uuid(), 'number' => 2, 'next_due_at' => null]);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'stale')->assertJsonPath('data.result', null);
    }

    public function test_materialized_read_never_recounts_source_tables(): void
    {
        $s = $this->scenario();
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.state', 'fresh');
            foreach (DB::getQueryLog() as $query) {
                $this->assertFalse(str_contains(strtolower($query['query']), 'count(') && str_contains($query['query'], 'people'));
            }
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_history_paginates_without_old_totals_and_scopes_require_context_confirmation(): void
    {
        $s = $this->scenario();
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.history.meta.total', 1)->assertJsonMissingPath('data.history.items.0.result');
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $s['org']->id, 'actor_user_id' => $s['user']->id, 'revision' => $revision, 'confirmed' => false]])->withHeader('X-Tenant-Revision', $revision)
            ->postJson($s['root'], [...$s['input'], 'confirm' => true])->assertConflict();
    }

    public function test_contract_expiry_summary_keeps_signed_state_and_inclusive_end_date(): void
    {
        $this->travelTo('2026-10-20 10:00:00 UTC');
        config(['dashboard_indicators.processing_enabled' => true, 'queue.default' => 'database']);
        Queue::fake([CalculateDashboardIndicator::class]);
        [$org,$user,$contract,$context] = $this->documentScenario(['reporting.direction', 'administrative.read', 'administrative.write', 'administrative.validate']);
        $contract->forceFill(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-25'])->save();
        $this->declareContractSignature($org, $contract, $context);
        $this->actingAsWithMfa($user);
        $s = ['org' => $org, 'user' => $user, 'root' => '/api/v1/tenants/'.$org->id.'/dashboards/expiring_contracts/indicator', 'input' => ['view' => 'direction', 'from' => '2026-10-20', 'to' => '2026-10-25']];
        $row = $this->requestIndicator($s);
        $this->process($s, $row);
        $this->getJson($this->url($s))->assertOk()->assertJsonPath('data.result.total', 1)->assertJsonPath('data.result.groups.0.state', 'signed');
    }

    public function test_empty_migration_can_roundtrip_without_touching_source_rows(): void
    {
        $this->assertDatabaseCount('dashboard_indicators', 0);
        $migration = require database_path('migrations/2026_10_20_184653_create_dashboard_indicator_snapshots.php');
        $migration->down();
        $migration->up();
        $this->assertSame([], app(TenantSchemaInspector::class)->violations());
    }

    public function test_rollback_refuses_to_discard_indicator_history(): void
    {
        $s = $this->scenario();
        $this->requestIndicator($s);
        $migration = require database_path('migrations/2026_10_20_184653_create_dashboard_indicator_snapshots.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_private_indicator_quota_is_enforced_before_queueing(): void
    {
        $s = $this->scenario(['reporting.direction', 'administrative.read']);
        $service = app(DashboardIndicatorService::class);
        $records = app(DashboardIndicatorRepository::class);
        for ($day = 0; $day < DashboardIndicatorService::MAX_INDICATORS; $day++) {
            $date = CarbonImmutable::parse('2026-01-01')->addDays($day)->toDateString();
            $selector = $service->selector($this->personContext($s['org'], $s['user']), 'expiring_contracts', ['view' => 'direction', 'from' => $date, 'to' => $date]);
            $records->save(null, ['organization_id' => $s['org']->id, 'actor_user_id' => $s['user']->id, ...$selector, 'status' => 'queued', 'version' => (string) Str::uuid()]);
        }
        $this->postJson(str_replace('active_people', 'expiring_contracts', $s['root']), [...$s['input'], 'confirm' => true])->assertConflict()->assertJsonPath('code', 'DASHBOARD_INDICATOR_LIMIT');
        Queue::assertNothingPushed();
    }
}
