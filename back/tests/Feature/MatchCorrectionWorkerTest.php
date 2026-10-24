<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Infrastructure\Queue\RecalculateMatchScore;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Support\Queue\JobContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchCorrections;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchCorrectionWorkerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesMatchCorrections,ProvidesPeopleAccess;

    public function test_real_job_middleware_restores_tenant_and_repeated_delivery_creates_one_aggregate(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $job = new RecalculateMatchScore(new JobContext((string) Str::uuid(), $s['org']->id, $s['writer']->id), $s['record']['id'], $correction['id']);
        Bus::fake()->except(RecalculateMatchScore::class);
        Bus::dispatchSync($job);
        $count = DB::table('organization_audit_events')->count();
        $original = DB::table('match_score_recalculations')->first();
        $this->assertNotNull($original);
        Bus::dispatchSync($job);
        $this->assertDatabaseCount('match_score_recalculations', 1);
        $this->assertDatabaseCount('organization_audit_events', $count);
        $this->assertEquals($original, DB::table('match_score_recalculations')->first());
        $this->assertSame($s['org']->id, $original->organization_id);
    }

    public function test_permission_revocation_prevents_worker_changes_and_an_authorized_retry_can_recover(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $assign = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['writer']->id)->firstOrFail();
        $assign->forceFill(['status' => 'revoked'])->save();
        try {
            app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
            $this->fail('Revoked permissions must prevent recalculation.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertDatabaseCount('match_score_recalculations', 0);
        $assign->forceFill(['status' => 'active'])->save();
        $this->postJson($s['root'].'/corrections/'.$correction['id'].'/retries')->assertStatus(202);
        Bus::assertDispatchedTimes(RecalculateMatchScore::class, 2);
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
        $this->assertDatabaseCount('match_score_recalculations', 1);
        $this->postJson($s['root'].'/corrections/'.$correction['id'].'/retries')->assertStatus(202);
        Bus::assertDispatchedTimes(RecalculateMatchScore::class, 2);
    }

    public function test_audit_failure_does_not_leave_a_partial_completion_and_retry_succeeds(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('audit unavailable'));
        try {
            app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
            $this->fail('Audit failure must roll back the completion.');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('match_score_recalculations', 0);
    }

    public function test_cache_failure_retains_pending_correction_and_hides_stale_aggregate(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $this->mock(TenantCache::class)->shouldReceive('forget')->once()->andThrow(new \RuntimeException('cache unavailable'));
        try {
            app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
            $this->fail('Cache invalidation failure must retain pending state.');
        } catch (\RuntimeException $e) {
            $this->assertSame('cache unavailable', $e->getMessage());
        }
        $this->assertDatabaseCount('match_score_recalculations', 0);
        $this->getJson($s['root'].'/games')->assertOk()->assertJsonPath('scorecard.series', null)->assertJsonPath('scorecard.recalculation_pending', true);
    }

    public function test_pending_recalculation_prevents_other_sporting_mutations(): void
    {
        $s = $this->correctionScenario(false);
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $this->postJson($s['root'].'/transitions', ['version' => $correction['match_version'], 'status' => 'cancelled', 'reason' => 'Annulation concurrente à la correction.'])->assertConflict()->assertJsonPath('code', 'MATCH_RECALCULATION_PENDING');
        $this->postJson($s['root'].'/games', [...$this->scorePayload($s), 'version' => $correction['match_version']])->assertConflict()->assertJsonPath('code', 'MATCH_RECALCULATION_PENDING');
    }

    #[Group('tenant-isolation')]
    public function test_another_tenant_cannot_execute_or_retry_the_correction(): void
    {
        $s = $this->correctionScenario();
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $foreign = MatchRecord::factory()->create();
        $root = $this->matchRoot($s['org']).'/'.$foreign->id.'/corrections';
        $this->getJson($root)->assertNotFound();
        $this->postJson($root.'/'.$correction['id'].'/retries')->assertNotFound();
        $this->postJson($s['root'].'/corrections/'.Str::uuid().'/retries')->assertNotFound();
        $this->assertDatabaseCount('match_score_recalculations', 0);
    }

    public function test_dispatch_failure_keeps_a_durable_request_for_explicit_recovery(): void
    {
        $s = $this->correctionScenario();
        $bus = app(Dispatcher::class);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('queue unavailable'));
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->assertJsonPath('data.state', 'pending')->json('data');
        $this->assertDatabaseCount('match_score_corrections', 1);
        $this->assertDatabaseCount('match_score_recalculations', 0);
        $this->app->instance(Dispatcher::class, $bus);
        $this->postJson($s['root'].'/corrections/'.$correction['id'].'/retries')->assertStatus(202);
    }
}
