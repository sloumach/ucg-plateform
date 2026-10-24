<?php

namespace Tests\Feature;

use App\Modules\Scheduling\Application\Services\ProcessCalendarInvitations;
use App\Modules\Scheduling\Domain\Models\CalendarInvitationDelivery;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Infrastructure\Queue\RunTenantScheduledTask;
use App\Support\Queue\JobContext;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCalendarInvitations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarInvitationWorkerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCalendarInvitations,ProvidesPeopleAccess;

    public function test_retries_and_different_runs_keep_one_delivery_per_occurrence_and_stop_after_response(): void
    {
        [$org, $editor, , , $activity] = $this->invitationScenario();
        $record = $this->issueInvitation($org, $editor, $activity);
        $run = (string) Str::uuid();
        $this->processInvitations($org, $run);
        $this->processInvitations($org, $run);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 1);
        $this->travelTo('2026-10-18 05:00:00');
        $this->processInvitations($org);
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 2);
        $this->travelTo('2026-10-18 06:00:00');
        $this->processInvitations($org);
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 3);
        $record->refresh()->forceFill(['response' => 'present', 'responded_at' => now()])->save();
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 3);
    }

    public function test_late_worker_catches_only_the_nearest_reminder_and_sends_nothing_after_deadline(): void
    {
        [$org, $editor, , , $activity] = $this->invitationScenario();
        $this->issueInvitation($org, $editor, $activity);
        $this->processInvitations($org);
        $this->travelTo('2026-10-18 06:30:00');
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 2);
        $this->assertDatabaseHas('calendar_invitation_deliveries', ['kind' => 'reminder', 'offset_minutes' => 60]);
        $this->travelTo('2026-10-18 07:00:00');
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 2);
    }

    public function test_initial_delivery_catches_up_without_immediate_redundant_reminder(): void
    {
        [$org, $editor, , , $activity] = $this->invitationScenario();
        $this->issueInvitation($org, $editor, $activity);
        $this->travelTo('2026-10-18 06:30:00');
        $this->processInvitations($org);
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 1);
    }

    #[Group('tenant-isolation')]
    public function test_revoked_issuer_or_recipient_rights_block_delivery_and_recovery_does_not_duplicate(): void
    {
        [$org, $editor, $recipient, , $activity] = $this->invitationScenario();
        $record = $this->issueInvitation($org, $editor, $activity);
        $grant = OrganizationRoleAssignment::query()->where('user_id', $recipient->id)->firstOrFail();
        $grant->forceFill(['status' => 'revoked'])->save();
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 0);
        $this->assertDatabaseHas('calendar_invitations', ['id' => $record->id, 'blocked_reason' => 'recipient_unavailable']);
        $grant->forceFill(['status' => 'active'])->save();
        $issuer = OrganizationRoleAssignment::query()->where('user_id', $editor->id)->firstOrFail();
        $issuer->forceFill(['status' => 'revoked'])->save();
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 0);
        $this->assertDatabaseHas('calendar_invitations', ['id' => $record->id, 'blocked_reason' => 'issuer_unavailable']);
        $issuer->forceFill(['status' => 'active'])->save();
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 1);
    }

    public function test_departed_unlinked_removed_and_changed_activities_never_receive_obsolete_reminders(): void
    {
        [$org, $editor, , $person, $activity] = $this->invitationScenario();
        $record = $this->issueInvitation($org, $editor, $activity);
        $person->forceFill(['status' => 'former'])->save();
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 0);
        $person->forceFill(['status' => 'active'])->save();
        $this->processInvitations($org);
        $this->travelTo('2026-10-18 05:00:00');
        $activity->forceFill(['version' => (string) Str::uuid()])->save();
        $this->processInvitations($org);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 1);
        $this->assertDatabaseHas('calendar_invitations', ['id' => $record->id]);
    }

    #[Group('tenant-isolation')]
    public function test_system_job_uses_tenant_registry_and_replay_keeps_delivery_idempotent(): void
    {
        [$org, $editor, , , $activity] = $this->invitationScenario();
        $this->issueInvitation($org, $editor, $activity);
        config(['tenancy.cache_store' => 'database', 'tenancy.scheduled_tasks' => ['calendar-invitations' => ProcessCalendarInvitations::class]]);
        $run = (string) Str::uuid();
        $job = new RunTenantScheduledTask(new JobContext($run, $org->id), 'calendar-invitations', $run);
        dispatch_sync($job);
        dispatch_sync($job);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 1);
        $this->assertSame($run, CalendarInvitationDelivery::query()->firstOrFail()->run_id);
    }
}
