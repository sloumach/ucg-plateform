<?php

namespace Tests\Fixtures;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Application\Services\ProcessCalendarChanges;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarParticipant;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Support\Str;

trait ProvidesCalendarChanges
{
    /** @param list<string> $readerPermissions
     * @return array{Organization,User,User,Person,CalendarActivity}
     */
    private function changeScenario(array $readerPermissions = ['calendar.read']): array
    {
        $this->travelTo('2026-10-17 10:00:00');
        $org = Organization::factory()->create();
        $writer = User::factory()->withMfa()->create();
        $reader = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $writer, ['calendar.read', 'calendar.write', 'calendar.invitations.manage']);
        $this->grantPeopleAccess($org, $reader, $readerPermissions);
        $person = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $reader->id]);
        $activity = CalendarActivity::factory()->create(['organization_id' => $org->id, 'created_by' => $writer->id, 'title' => 'Entraînement pilote',
            'description' => 'SECRET_OPERATIONAL_NOTE', 'location' => 'SECRET_ROOM', 'url' => 'https://private.example.test/SECRET_LINK',
            'starts_at' => '2026-10-18 08:00:00', 'ends_at' => '2026-10-18 09:00:00']);
        CalendarParticipant::factory()->create(['organization_id' => $org->id, 'activity_id' => $activity->id, 'person_id' => $person->id]);
        $this->actingAsWithMfa($writer);

        return [$org, $writer, $reader, $person, $activity];
    }

    private function changeRoot(Organization $org): string
    {
        return '/api/v1/tenants/'.$org->id.'/calendar';
    }

    private function changePath(Organization $org, CalendarActivity $activity): string
    {
        return $this->changeRoot($org).'/activities/'.$activity->id;
    }

    /** @return array<string,mixed> */
    private function rescheduleInput(CalendarActivity $activity): array
    {
        return ['version' => $activity->version, 'starts_at' => '2026-10-18T10:00:00+00:00', 'ends_at' => '2026-10-18T11:00:00+00:00',
            'origin_timezone' => 'UTC', 'change_reason' => 'Indisponibilité du créneau initial.'];
    }

    private function processChanges(Organization $org): void
    {
        $runId = (string) Str::uuid();
        app(ProcessCalendarChanges::class)->run(new TenantExecutionContext($org->id, $runId, 'UTC', 'fr'), $runId);
    }
}
