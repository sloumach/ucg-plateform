<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Application\Services\CalendarInvitationService;
use App\Modules\Scheduling\Application\Services\ProcessCalendarInvitations;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Support\Str;

trait ProvidesCalendarInvitations
{
    /** @return array{Organization,User,User,Person,CalendarActivity} */
    private function invitationScenario(): array
    {
        $this->travelTo('2026-10-17 10:00:00');
        $org = Organization::factory()->create();
        $editor = User::factory()->withMfa()->create();
        $recipient = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $editor, ['calendar.read', 'calendar.write', 'calendar.invitations.manage']);
        $this->grantPeopleAccess($org, $recipient, ['calendar.read', 'calendar.invitations.respond']);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $recipient->id]);
        $activity = CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $editor->id,
            'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00']);
        CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $activity->id, 'person_id' => $person->id]);
        $this->actingAsWithMfa($editor);

        return [$org, $editor, $recipient, $person, $activity];
    }

    /** @return array<string,mixed> */
    private function invitationInput(CalendarActivity $activity): array
    {
        return ['activity_version' => $activity->version, 'response_deadline' => '2026-10-18T07:00:00+00:00',
            'reminder_offsets' => [60, 120], 'confirm_invitations' => true];
    }

    private function issueInvitation(Organization $org, User $editor, CalendarActivity $activity): CalendarInvitation
    {
        app(CalendarInvitationService::class)->issue($this->personContext($org, $editor), $activity->id, $this->invitationInput($activity), null);

        return CalendarInvitation::query()->where('organization_id', $org->id)->where('activity_id', $activity->id)->firstOrFail();
    }

    private function processInvitations(Organization $org, ?string $runId = null): void
    {
        $id = $runId ?? (string) Str::uuid();
        app(ProcessCalendarInvitations::class)->run(new TenantExecutionContext($org->id, $id, 'UTC', 'fr'), $id);
    }

    private function invitationRoot(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/calendar';
    }

    private function issuePath(Organization $org, CalendarActivity $activity): string
    {
        return $this->invitationRoot($org).'/activities/'.$activity->id.'/invitations';
    }
}
