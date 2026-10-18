<?php

namespace Tests\Feature;

use App\Modules\Competition\Application\Contracts\MatchScorePublisher;
use App\Modules\Competition\Application\Services\MatchCorrectionService;
use App\Modules\Competition\Infrastructure\Broadcasting\LaravelMatchScorePublisher;
use App\Modules\Competition\Infrastructure\Broadcasting\MatchScoreChanged;
use App\Modules\Identity\Application\Services\MfaSession;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesMatchCorrections;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class MatchRealtimeTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesMatchCorrections, ProvidesPeopleAccess;

    protected function setUp(): void
    {
        parent::setUp();
        config(['broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret', 'broadcasting.connections.reverb.app_id' => 'test-app']);
    }

    public function test_authorized_reader_receives_a_private_signature_without_scores(): void
    {
        $s = $this->scoreScenario();
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/realtime/auth', $this->channelBody($s))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertExactJson([
            'auth' => 'test-key:'.hash_hmac('sha256', '123.456:'.$this->channelBody($s)['channel_name'], 'test-secret'),
        ]);
    }

    public function test_unrelated_reader_cannot_subscribe_to_a_private_match(): void
    {
        $s = $this->scoreScenario();
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $other, ['sports.read']);
        $this->actingAsWithMfa($other)->postJson($s['root'].'/realtime/auth', $this->channelBody($s))->assertNotFound();
    }

    public function test_foreign_organization_cannot_subscribe_to_a_known_match(): void
    {
        $s = $this->scoreScenario();
        $other = Organization::factory()->create();
        $this->grantPeopleAccess($other, $s['writer'], ['sports.read', 'sports.write']);
        $this->postJson($this->matchRoot($other).'/'.$s['record']['id'].'/realtime/auth', [
            'socket_id' => '123.456', 'channel_name' => 'private-tenants.'.$other->id.'.matches.'.$s['record']['id'],
        ])->assertNotFound();
    }

    public function test_global_authorization_route_cannot_bypass_tenant_confirmation(): void
    {
        $s = $this->scoreScenario();
        $this->postJson('/api/broadcasting/auth', $this->channelBody($s))->assertForbidden();
    }

    public function test_authentication_is_required(): void
    {
        $this->postJson('/api/v1/tenants/'.Str::uuid().'/matches/'.Str::uuid().'/realtime/auth', [])->assertUnauthorized();
    }

    public function test_missing_permission_denies_authorization(): void
    {
        $s = $this->scoreScenario();
        $assignment = OrganizationRoleAssignment::query()->where('organization_id', $s['org']->id)->where('user_id', $s['reader']->id)->firstOrFail();
        OrganizationRole::query()->findOrFail($assignment->role_id)->forceFill(['permissions' => []])->save();
        $this->actingAsWithMfa($s['reader'])->postJson($s['root'].'/realtime/auth', $this->channelBody($s))->assertForbidden();
        $this->getJson($s['root'].'/games?per_page=1')->assertForbidden();
    }

    public function test_unconfirmed_organization_is_rejected(): void
    {
        $s = $this->scoreScenario();
        $revision = (string) Str::uuid();
        $this->withSession([ActiveOrganizationSession::KEY => ['organization_id' => $s['org']->id, 'actor_user_id' => $s['writer']->id, 'revision' => $revision, 'confirmed' => false]])
            ->withHeader('X-Tenant-Revision', $revision)->postJson($s['root'].'/realtime/auth', $this->channelBody($s))->assertConflict()->assertJsonPath('code', 'TENANT_CONFIRMATION_REQUIRED');
    }

    public function test_channel_socket_and_unexpected_fields_are_validated(): void
    {
        $s = $this->scoreScenario();
        $this->postJson($s['root'].'/realtime/auth', ['socket_id' => 'invalid', 'channel_name' => 'private-users.1', 'management' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['socket_id', 'channel_name', 'management']);
    }

    public function test_committed_score_dispatches_only_an_invalidation_on_the_broadcast_queue(): void
    {
        $s = $this->scoreScenario();
        Event::fake([MatchScoreChanged::class]);
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated();
        Event::assertDispatchedTimes(MatchScoreChanged::class, 1);
        Event::assertDispatched(MatchScoreChanged::class, function (MatchScoreChanged $event) use ($s): bool {
            $this->assertSame(['event_id', 'organization_id', 'match_id', 'changed_at'], array_keys($event->broadcastWith()));
            $this->assertSame($this->channelBody($s)['channel_name'], $event->broadcastOn()[0]->name);
            $this->assertSame('broadcasts', $event->broadcastQueue());
            $this->assertSame('match.score.changed', $event->broadcastAs());
            $this->assertTrue(Str::isUuid($event->eventId));
            $this->assertSame('2026-10-18T10:00:00.000000Z', $event->changedAt);

            return true;
        });
    }

    public function test_rolled_back_changes_never_dispatch_and_nested_changes_wait_for_commit(): void
    {
        $s = $this->scoreScenario();
        Event::fake([MatchScoreChanged::class]);
        $publisher = app(MatchScorePublisher::class);
        DB::beginTransaction();
        $publisher->changed($s['org']->id, $s['record']['id']);
        Event::assertNotDispatched(MatchScoreChanged::class);
        DB::rollBack();
        Event::assertNotDispatched(MatchScoreChanged::class);
        DB::beginTransaction();
        $publisher->changed($s['org']->id, $s['record']['id']);
        Event::assertNotDispatched(MatchScoreChanged::class);
        DB::commit();
        Event::assertDispatchedTimes(MatchScoreChanged::class, 1);
    }

    public function test_stale_score_does_not_emit_a_signal(): void
    {
        $s = $this->scoreScenario();
        Event::fake([MatchScoreChanged::class]);
        $this->postJson($s['root'].'/games', [...$this->scorePayload($s), 'version' => (string) Str::uuid()])->assertConflict();
        Event::assertNotDispatched(MatchScoreChanged::class);
    }

    public function test_submission_approval_and_dispute_each_invalidate_the_live_view(): void
    {
        $s = $this->resultScenario();
        Event::fake([MatchScoreChanged::class]);
        $result = $this->postJson($s['root'].'/results', $this->resultPayload($s))->assertCreated()->json('data');
        $this->actingAsWithMfa($s['reader']);
        $result = $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($result))->assertOk()->json('data');
        $decision = $this->decisionPayload($result);
        unset($decision['confirm_evidence']);
        $this->postJson($s['root'].'/results/disputes', $decision)->assertOk();
        Event::assertDispatchedTimes(MatchScoreChanged::class, 3);
    }

    public function test_correction_completion_invalidates_once_and_duplicate_worker_is_silent(): void
    {
        $s = $this->correctionScenario(false);
        Event::fake([MatchScoreChanged::class]);
        $body = $this->correctionPayload($s);
        $correction = $this->postJson($s['root'].'/corrections', $body)->assertStatus(202)->json('data');
        $this->postJson($s['root'].'/corrections', $body)->assertStatus(202);
        Event::assertDispatchedTimes(MatchScoreChanged::class, 1);
        $service = app(MatchCorrectionService::class);
        $context = $this->personContext($s['org'], $s['writer']);
        $service->process($context, $s['record']['id'], $correction['id']);
        $service->process($context, $s['record']['id'], $correction['id']);
        Event::assertDispatchedTimes(MatchScoreChanged::class, 2);
        $this->assertDatabaseCount('match_score_recalculations', 1);
    }

    public function test_live_snapshot_exposes_only_scores_and_status_and_hides_pending_aggregates(): void
    {
        $s = $this->correctionScenario();
        $snapshot = $this->getJson($s['root'].'/live')->assertOk()->assertJsonPath('data.status', 'validated')->assertJsonPath('data.series.standings.0.score', 1)->json('data');
        $this->assertSame(['match_version', 'score_revision', 'recalculation_pending', 'outcome', 'winner_ids', 'status', 'series', 'observed_at'], array_keys($snapshot));
        $correction = $this->postJson($s['root'].'/corrections', $this->correctionPayload($s))->assertStatus(202)->json('data');
        $this->getJson($s['root'].'/live')->assertOk()->assertJsonPath('data.status', 'disputed')->assertJsonPath('data.series', null)->assertJsonPath('data.recalculation_pending', true);
        app(MatchCorrectionService::class)->process($this->personContext($s['org'], $s['writer']), $s['record']['id'], $correction['id']);
        $this->getJson($s['root'].'/live')->assertOk()->assertJsonPath('data.recalculation_pending', false)->assertJsonPath('data.series.standings.0.score', 0)->assertJsonPath('data.score_revision', $correction['id']);
    }

    public function test_live_snapshot_checks_audience_and_rejects_unknown_inputs(): void
    {
        $s = $this->scoreScenario();
        $this->getJson($s['root'].'/live?include=proof')->assertUnprocessable()->assertJsonValidationErrors(['include']);
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($s['org'], $other, ['sports.read']);
        $this->actingAsWithMfa($other)->getJson($s['root'].'/live')->assertNotFound();
    }

    public function test_transport_outage_does_not_turn_a_committed_score_into_an_http_failure(): void
    {
        $s = $this->scoreScenario();
        $originalEvents = app(Dispatcher::class);
        $originalLogger = app(LoggerInterface::class);
        $this->mock(Dispatcher::class, function (MockInterface $mock): void {
            $mock->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('private transport failure'));
        });
        $this->mock(LoggerInterface::class, function (MockInterface $mock) use ($s): void {
            $mock->shouldReceive('warning')->once()->with('match.score.broadcast_failed', \Mockery::on(fn (array $context): bool => $context['tenant_id'] === $s['org']->id && ! isset($context['exception'])));
        });
        $publisher = app(LaravelMatchScorePublisher::class);
        $this->app->instance(Dispatcher::class, $originalEvents);
        $this->app->instance(LoggerInterface::class, $originalLogger);
        $this->app->instance(MatchScorePublisher::class, $publisher);
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated();
        $this->assertDatabaseCount('match_game_instances', 1);
    }

    public function test_forfeit_winner_is_visible_only_after_approval(): void
    {
        $s = $this->resultScenario(false);
        $submission = $this->postJson($s['root'].'/results', $this->resultPayload($s, 'forfeit'))->assertCreated()->json('data');
        $this->getJson($s['root'].'/live')->assertOk()->assertJsonPath('data.outcome', null)->assertJsonPath('data.winner_ids', []);
        $this->actingAsWithMfa($s['reader']);
        $this->postJson($s['root'].'/results/approvals', $this->decisionPayload($submission))->assertOk();
        $this->getJson($s['root'].'/live')->assertOk()->assertJsonPath('data.outcome', 'forfeit')->assertJsonPath('data.winner_ids', [$s['record']['participants'][0]['id']]);
    }

    public function test_real_dispatch_uses_a_retryable_broadcast_job_without_contacting_reverb(): void
    {
        $s = $this->scoreScenario();
        Queue::fake([BroadcastEvent::class]);
        config(['broadcasting.default' => 'reverb']);
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated();
        Queue::assertPushedOn('broadcasts', BroadcastEvent::class, function (BroadcastEvent $job): bool {
            $this->assertSame(3, $job->tries);
            $this->assertSame(30, $job->timeout);
            $this->assertSame([1, 5, 15], $job->backoff);

            return $job->event instanceof MatchScoreChanged;
        });
    }

    public function test_disabled_realtime_keeps_scores_working_without_filling_the_queue(): void
    {
        $s = $this->scoreScenario();
        Queue::fake([BroadcastEvent::class]);
        config(['broadcasting.default' => 'null']);
        $this->postJson($s['root'].'/games', $this->scorePayload($s))->assertCreated();
        Queue::assertNotPushed(BroadcastEvent::class);
        $this->getJson($s['root'].'/live')->assertOk()->assertJsonPath('data.series.standings.0.score', 1);
    }

    public function test_live_reads_and_channel_signatures_require_current_mfa_proof(): void
    {
        $s = $this->scoreScenario();
        $this->withSession([MfaSession::PROOF_KEY => null]);
        $this->postJson($s['root'].'/realtime/auth', $this->channelBody($s))->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
        $this->getJson($s['root'].'/live')->assertForbidden()->assertJsonPath('code', 'MFA_CHALLENGE_REQUIRED');
    }

    /** @param array<string,mixed> $s
     * @return array{socket_id:string,channel_name:string} */
    private function channelBody(array $s): array
    {
        return ['socket_id' => '123.456', 'channel_name' => 'private-tenants.'.$s['org']->id.'.matches.'.$s['record']['id']];
    }
}
