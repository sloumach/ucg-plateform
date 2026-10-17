<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesCalendarInvitations;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class CalendarInvitationControllerTest extends TestCase
{
    use AuthenticatesWithMfa,LazilyRefreshDatabase,ProvidesCalendarInvitations,ProvidesPeopleAccess;

    public function test_guest_is_unauthorized_and_writer_requires_explicit_invitation_permission(): void
    {
        $org = Organization::factory()->create();
        $this->getJson($this->invitationRoot($org).'/invitations')->assertUnauthorized();
        [$org, , $recipient, , $activity] = $this->invitationScenario();
        $this->actingAsWithMfa($recipient);
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertForbidden();
        $this->getJson($this->issuePath($org, $activity))->assertForbidden();
        $this->assertDatabaseCount('calendar_invitations', 0);
    }

    public function test_issue_prepares_one_invitation_per_participant_without_sending_in_http_and_refuses_duplicate(): void
    {
        [$org, , , , $activity] = $this->invitationScenario();
        Mail::fake();
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertCreated()
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.response', 'pending')->assertJsonPath('data.0.sent_at', null)
            ->assertJsonPath('data.0.reminder_offsets', [60, 120])->assertJsonMissingPath('data.0.recipient_id')
            ->assertJsonMissingPath('data.0.issued_by')->assertHeader('Cache-Control', 'no-store, private');
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertConflict()->assertJsonPath('code', 'CALENDAR_INVITATIONS_ALREADY_ISSUED');
        $this->assertDatabaseCount('calendar_invitations', 1);
        $this->assertDatabaseCount('calendar_invitation_deliveries', 0);
        $this->assertDatabaseCount('organization_audit_events', 1);
        Mail::assertNothingSent();
    }

    /** @return array<string,array{array<string,mixed>,string}> */
    public static function invalidInput(): array
    {
        return ['past deadline' => [['response_deadline' => '2026-10-17T09:00:00+00:00'], 'response_deadline'],
            'after activity' => [['response_deadline' => '2026-10-18T09:00:00+00:00'], 'response_deadline'],
            'offset missing' => [['response_deadline' => '2026-10-18T07:00:00'], 'response_deadline'],
            'zero offset' => [['reminder_offsets' => [0]], 'reminder_offsets.0'],
            'string offset' => [['reminder_offsets' => ['60']], 'reminder_offsets.0'],
            'duplicate offsets' => [['reminder_offsets' => [60, 60]], 'reminder_offsets.0'],
            'too many' => [['reminder_offsets' => [1, 2, 3, 4]], 'reminder_offsets'],
            'too far' => [['reminder_offsets' => [10081]], 'reminder_offsets.0'],
            'no confirmation' => [['confirm_invitations' => false], 'confirm_invitations'],
            'foreign recipient field' => [['recipient_id' => 1], 'recipient_id'],
            'dotted field' => [['response.deadline' => 'x'], 'response.deadline']];
    }

    /** @param array<string,mixed> $changes */
    #[DataProvider('invalidInput')]
    public function test_invalid_issue_returns_422_without_writes(array $changes, string $field): void
    {
        [$org, , , , $activity] = $this->invitationScenario();
        $this->postJson($this->issuePath($org, $activity), [...$this->invitationInput($activity), ...$changes])->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('calendar_invitations', 0);
    }

    public function test_foreign_archived_stale_and_empty_activities_cannot_be_invited(): void
    {
        [$org, , , , $activity] = $this->invitationScenario();
        $foreign = CalendarActivity::factory()->create();
        $this->postJson($this->issuePath($org, $foreign), $this->invitationInput($foreign))->assertNotFound();
        $this->postJson($this->issuePath($org, $activity), [...$this->invitationInput($activity), 'activity_version' => (string) Str::uuid()])->assertConflict()->assertJsonPath('code', 'CALENDAR_VERSION_STALE');
        $activity->participants()->delete();
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertUnprocessable()->assertJsonValidationErrors('record');
        $activity->forceFill(['archived_at' => now()])->save();
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertConflict()->assertJsonPath('code', 'CALENDAR_ARCHIVED');
    }

    public function test_only_current_linked_recipient_can_respond_and_other_users_receive_404(): void
    {
        [$org, $editor, $recipient, $person, $activity] = $this->invitationScenario();
        $record = $this->issueInvitation($org, $editor, $activity);
        $this->processInvitations($org);
        $record->refresh();
        $path = $this->invitationRoot($org).'/invitations/'.$record->id;
        $this->actingAsWithMfa($recipient);
        $this->getJson($this->invitationRoot($org).'/invitations?per_page=1')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.can_respond', true);
        $response = $this->patchJson($path, ['version' => $record->version, 'response' => 'present'])->assertOk()->assertJsonPath('data.response', 'present')
            ->assertJsonPath('notification.message', 'Votre réponse à la convocation a été enregistrée.')->json('data');
        $this->assertDatabaseHas('calendar_invitations', ['id' => $record->id, 'response' => 'present']);
        $this->patchJson($path, ['version' => $record->version, 'response' => 'absent'])->assertConflict()->assertJsonPath('code', 'CALENDAR_INVITATION_STALE');
        $this->patchJson($path, ['version' => $response['version'], 'response' => 'pending'])->assertOk()->assertJsonPath('data.responded_at', null);
        $other = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $other, ['calendar.read', 'calendar.invitations.respond']);
        $this->actingAsWithMfa($other);
        $this->getJson($this->invitationRoot($org).'/invitations')->assertJsonPath('meta.total', 0);
        $this->patchJson($path, ['version' => $response['version'], 'response' => 'absent'])->assertNotFound();
        $this->getJson($path.'/deliveries')->assertNotFound();
        $this->actingAsWithMfa($recipient);
        $person->forceFill(['status' => 'former'])->save();
        $this->getJson($this->invitationRoot($org).'/invitations')->assertJsonPath('meta.total', 0);
        $this->patchJson($path, ['version' => $response['version'], 'response' => 'absent'])->assertNotFound();
    }

    public function test_activity_change_hides_inbox_and_rejects_old_reply_but_retains_manager_history(): void
    {
        [$org, $editor, $recipient, , $activity] = $this->invitationScenario();
        $record = $this->issueInvitation($org, $editor, $activity);
        $this->processInvitations($org);
        $activity->forceFill(['version' => (string) Str::uuid()])->save();
        $this->actingAsWithMfa($recipient);
        $this->getJson($this->invitationRoot($org).'/invitations')->assertJsonPath('meta.total', 0);
        $this->patchJson($this->invitationRoot($org).'/invitations/'.$record->id, ['version' => $record->version, 'response' => 'absent'])
            ->assertConflict()->assertJsonPath('code', 'CALENDAR_INVITATION_OBSOLETE');
        $this->actingAsWithMfa($editor);
        $this->getJson($this->issuePath($org, $activity))->assertJsonPath('data.0.current', false);
        $this->getJson($this->invitationRoot($org).'/invitations/'.$record->id.'/deliveries')->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.run_id');
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertCreated();
        $this->assertDatabaseCount('calendar_invitations', 2);
    }

    public function test_deadline_closes_answers_and_audit_failure_rolls_back_the_entire_issue(): void
    {
        [$org, $editor, $recipient, , $activity] = $this->invitationScenario();
        $record = $this->issueInvitation($org, $editor, $activity);
        $this->processInvitations($org);
        $this->travelTo('2026-10-18 07:00:00');
        $this->actingAsWithMfa($recipient);
        $this->patchJson($this->invitationRoot($org).'/invitations/'.$record->id, ['version' => $record->version, 'response' => 'present'])
            ->assertConflict()->assertJsonPath('code', 'CALENDAR_INVITATION_EXPIRED');
        $this->assertDatabaseHas('calendar_invitations', ['id' => $record->id, 'response' => 'pending']);
        $this->travelTo('2026-10-17 10:00:00');
        $activity->forceFill(['version' => (string) Str::uuid()])->save();
        $this->actingAsWithMfa($editor);
        $this->mock(TenantAuditJournal::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        $this->postJson($this->issuePath($org, $activity), $this->invitationInput($activity))->assertStatus(500);
        $this->assertDatabaseCount('calendar_invitations', 1);
    }

    public function test_list_loads_people_labels_in_one_query_for_multiple_participants(): void
    {
        [$org, $editor, , , $activity] = $this->invitationScenario();
        foreach (range(1, 4) as $n) {
            $person = Person::factory()->create(['organization_id' => $org->id]);
            CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $activity->id, 'person_id' => $person->id]);
        }
        $this->issueInvitation($org, $editor, $activity);
        DB::enableQueryLog();
        $this->getJson($this->issuePath($org, $activity).'?per_page=3')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 5);
        $labels = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'pseudonym') && str_contains($q['query'], 'people'));
        DB::disableQueryLog();
        $this->assertCount(1, $labels);
    }
}
