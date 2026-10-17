<?php

namespace Tests\Feature;

use App\Modules\Scheduling\Application\Services\ProcessCalendarInvitations;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;
use App\Modules\Scheduling\Domain\Models\CalendarReservation;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCalendarChanges;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarChangeControllerTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesCalendarChanges, ProvidesPeopleAccess;

    public function test_guest_reader_and_foreign_activity_cannot_access_change_management(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->changeRoot($org).'/changes')->assertUnauthorized();
        [$org, , $reader, , $activity] = $this->changeScenario();
        $foreign = CalendarActivity::factory()->create();
        $this->getJson($this->changePath($org, $foreign).'/changes')->assertNotFound();
        $this->patchJson($this->changePath($org, $foreign), $this->rescheduleInput($foreign))->assertNotFound();
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changePath($org, $activity).'/changes')->assertForbidden();
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertForbidden();
        $this->assertDatabaseCount('calendar_changes', 0);
    }

    public function test_reschedule_retains_encrypted_history_and_prepares_a_single_notification_without_sending_in_http(): void
    {
        [$org, , $reader, , $activity] = $this->changeScenario();
        Mail::fake();
        $path = $this->changePath($org, $activity);
        $this->patchJson($path, $this->rescheduleInput($activity))->assertOk()->assertJsonPath('data.starts_at', '2026-10-18T10:00:00+00:00');
        $history = $this->getJson($path.'/changes?per_page=1')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.kind', 'rescheduled')->assertJsonPath('data.0.before.starts_at', '2026-10-18T08:00:00+00:00')
            ->assertJsonPath('data.0.after.starts_at', '2026-10-18T10:00:00+00:00')->assertJsonPath('data.0.recipients_count', 1)
            ->assertJsonPath('data.0.delivered_count', 0)->assertJsonMissingPath('data.0.actor_user_id')->json('data.0');
        $stored = DB::table('calendar_changes')->sole();
        $this->assertNotSame($history['reason'], $stored->reason);
        $this->assertNotSame($activity->title, $stored->title);
        $this->assertDatabaseHas('calendar_change_recipients', ['change_id' => $history['id'], 'recipient_id' => $reader->id, 'delivered_at' => null]);
        $this->patchJson($path, $this->rescheduleInput($activity))->assertConflict()->assertJsonPath('code', 'CALENDAR_VERSION_STALE');
        $this->assertDatabaseCount('calendar_changes', 1);
        $this->assertDatabaseCount('calendar_change_recipients', 1);
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changeRoot($org).'/changes')->assertJsonPath('meta.total', 0);
        $this->processChanges($org);
        $this->getJson($this->changeRoot($org).'/changes?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.change.kind', 'rescheduled')->assertJsonPath('data.0.current', true)
            ->assertJsonMissingPath('data.0.recipient_id')->assertJsonMissingPath('data.0.change.recipients_count')->assertDontSee('SECRET_');
        Mail::assertNothingSent();
    }

    /** @return array<string,array{mixed}> */
    public static function invalidReasons(): array
    {
        return ['missing' => [null], 'short' => ['Court'], 'whitespace' => [str_repeat(' ', 15)], 'long' => [str_repeat('x', 1001)], 'array' => [[]]];
    }

    #[DataProvider('invalidReasons')]
    public function test_reschedule_or_cancellation_requires_a_valid_operational_reason(mixed $reason): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $input = $this->rescheduleInput($activity);
        if ($reason === null) {
            unset($input['change_reason']);
        } else {
            $input['change_reason'] = $reason;
        }
        $this->patchJson($this->changePath($org, $activity), $input)->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'archive' => true, ...($reason === null ? [] : ['change_reason' => $reason])])
            ->assertUnprocessable()->assertJsonValidationErrors('change_reason');
        $this->assertDatabaseCount('calendar_changes', 0);
        $this->assertDatabaseCount('calendar_change_recipients', 0);
    }

    public function test_content_change_or_equal_period_does_not_create_a_spurious_reschedule(): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'title' => 'Titre corrigé'])->assertOk();
        $activity->refresh();
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'starts_at' => '2026-10-18T08:00:00+00:00',
            'ends_at' => '2026-10-18T09:00:00+00:00', 'origin_timezone' => 'UTC'])->assertOk();
        $this->assertDatabaseCount('calendar_changes', 0);
        $this->assertDatabaseCount('calendar_change_recipients', 0);
        $activity->refresh();
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'change_reason' => 'Motif sans modification effective.'])->assertUnprocessable();
    }

    public function test_cancellation_is_terminal_retains_participants_and_stops_pending_invitations(): void
    {
        [$org, , $reader, , $activity] = $this->changeScenario();
        $path = $this->changePath($org, $activity);
        $this->postJson($path.'/invitations', ['activity_version' => $activity->version, 'response_deadline' => '2026-10-18T07:00:00+00:00',
            'reminder_offsets' => [60], 'confirm_invitations' => true])->assertCreated();
        $data = $this->patchJson($path, ['version' => $activity->version, 'archive' => true, 'change_reason' => 'Annulation de la séance de préparation.'])
            ->assertOk()->assertJsonPath('data.cancelled_at', '2026-10-17T10:00:00+00:00')->json('data');
        $this->assertSame($data['cancelled_at'], $data['archived_at']);
        $this->assertDatabaseCount('calendar_participants', 1);
        $this->patchJson($path, ['version' => $data['version'], 'title' => 'Réactivation'])->assertConflict()->assertJsonPath('code', 'CALENDAR_ARCHIVED');
        $this->getJson($path.'/changes')->assertJsonPath('data.0.kind', 'cancelled');
        $runId = (string) Str::uuid();
        app(ProcessCalendarInvitations::class)->run(new TenantExecutionContext($org->id, $runId, 'UTC', 'fr'), $runId);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 0);
        $this->processChanges($org);
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changeRoot($org).'/changes')->assertOk()->assertJsonPath('data.0.change.kind', 'cancelled');
    }

    public function test_audit_failure_rolls_back_dates_history_and_notification_outbox_together(): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertStatus(500);
        $this->assertDatabaseHas('calendar_activities', ['id' => $activity->id, 'version' => $activity->version, 'starts_at' => '2026-10-18 08:00:00']);
        $this->assertDatabaseCount('calendar_changes', 0);
        $this->assertDatabaseCount('calendar_change_recipients', 0);
    }

    public function test_pagination_is_bounded_and_closed_for_both_history_and_inbox(): void
    {
        [$org, , , , $activity] = $this->changeScenario();
        $this->getJson($this->changePath($org, $activity).'/changes?per_page=101')->assertUnprocessable();
        $this->getJson($this->changeRoot($org).'/changes?recipient_id=1')->assertUnprocessable();
        $this->getJson($this->changeRoot($org).'/changes?page=0')->assertUnprocessable();
        $this->assertDatabaseCount('calendar_changes', 0);
    }

    public function test_resource_conflict_rejection_leaves_schedule_history_and_notifications_untouched(): void
    {
        [$org, $writer, , , $activity] = $this->changeScenario();
        $resource = CalendarResource::factory()->create(['organization_id' => $org->id]);
        CalendarReservation::factory()->create(['organization_id' => $org->id, 'activity_id' => $activity->id, 'resource_id' => $resource->id]);
        $other = CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $writer->id, 'starts_at' => '2026-10-18 10:00:00', 'ends_at' => '2026-10-18 11:00:00']);
        CalendarReservation::factory()->create(['organization_id' => $org->id, 'activity_id' => $other->id, 'resource_id' => $resource->id]);
        $this->patchJson($this->changePath($org, $activity), $this->rescheduleInput($activity))->assertConflict()->assertJsonPath('code', 'CALENDAR_CONFLICT_REVIEW_REQUIRED');
        $this->assertDatabaseCount('calendar_changes', 0);
        $this->assertDatabaseCount('calendar_change_recipients', 0);
        $this->assertSame('2026-10-18T08:00:00+00:00', $activity->refresh()->starts_at->toIso8601String());
    }

    public function test_cancellation_is_removed_from_the_ics_feed_while_retaining_notification_audience(): void
    {
        [$org, , $reader, , $activity] = $this->changeScenario(['calendar.read', 'calendar.export']);
        $token = bin2hex(random_bytes(32));
        $feed = CalendarFeed::factory()->create(['organization_id' => $org->id, 'user_id' => $reader->id, 'token_hash' => hash('sha256', $token)]);
        $url = '/api/v1/calendar-subscriptions/'.$org->id.'/'.$feed->id.'.ics?token='.$token;
        $this->get($url)->assertOk()->assertSee('SUMMARY:Entraînement pilote');
        $this->patchJson($this->changePath($org, $activity), ['version' => $activity->version, 'archive' => true, 'change_reason' => 'Annulation de la séance initiale.'])->assertOk();
        $this->get($url)->assertOk()->assertDontSee('SUMMARY:Entraînement pilote');
        $this->processChanges($org);
        $this->actingAsWithMfa($reader);
        $this->getJson($this->changeRoot($org).'/changes')->assertJsonPath('data.0.change.kind', 'cancelled');
    }

    public function test_paginated_inbox_eager_loads_history_and_current_versions_once(): void
    {
        [$org, , $reader, , $activity] = $this->changeScenario();
        foreach ([10, 12, 14] as $hour) {
            $this->patchJson($this->changePath($org, $activity), [...$this->rescheduleInput($activity),
                'starts_at' => '2026-10-18T'.$hour.':00:00+00:00', 'ends_at' => '2026-10-18T'.($hour + 1).':00:00+00:00'])->assertOk();
            $activity->refresh();
        }
        $this->processChanges($org);
        $this->actingAsWithMfa($reader);
        DB::enableQueryLog();
        $this->getJson($this->changeRoot($org).'/changes?per_page=3')->assertOk()->assertJsonCount(3, 'data');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn (array $q): bool => str_starts_with($q['query'], 'select * from "calendar_changes"')));
        $this->assertCount(1, array_filter($queries, fn (array $q): bool => str_starts_with($q['query'], 'select "id", "version" from "calendar_activities"')));
    }
}
