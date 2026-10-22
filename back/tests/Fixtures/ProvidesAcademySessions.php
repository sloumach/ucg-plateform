<?php

namespace Tests\Fixtures;

use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;

trait ProvidesAcademySessions
{
    /** @param list<string> $permissions
     * @return array{org: Organization, actor: User, cohort: Cohort, cycle: AcademyCycle, mentor: AcademyEnrollment, mentor_user: User, student: AcademyEnrollment, activity: CalendarActivity, path: string, body: array<string, mixed>} */
    private function sessionScenario(array $permissions = ['academy.read', 'academy.manage', 'academy.attendance.manage', 'calendar.read']): array
    {
        $this->travelTo('2026-10-22 12:00:00 UTC');
        $org = Organization::factory()->create();
        $actor = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($org, $actor, $permissions);
        $this->actingAsWithMfa($actor);
        $cohort = Cohort::factory()->create(['organization_id' => $org->id]);
        $cycle = AcademyCycle::factory()->create(['cohort_id' => $cohort->id]);
        $mentorUser = User::factory()->create();
        OrganizationMembership::factory()->create(['organization_id' => $org->id, 'user_id' => $mentorUser->id]);
        $mentorPerson = Person::factory()->create(['organization_id' => $org->id, 'user_id' => $mentorUser->id, 'status' => 'active', 'pseudonym' => 'Mentor pilote']);
        $mentor = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id, 'person_id' => $mentorPerson->id, 'role' => 'mentor']);
        $student = AcademyEnrollment::factory()->create(['cycle_id' => $cycle->id]);
        $activity = CalendarActivity::factory()->shared()->create(['organization_id' => $org->id, 'kind' => 'academy', 'starts_at' => '2026-10-22 10:00:00', 'ends_at' => '2026-10-22 11:00:00']);

        return ['org' => $org, 'actor' => $actor, 'cohort' => $cohort, 'cycle' => $cycle, 'mentor' => $mentor, 'mentor_user' => $mentorUser,
            'student' => $student, 'activity' => $activity, 'path' => '/api/v1/tenants/'.$org->id.'/academy/cohorts/'.$cohort->id.'/cycles/'.$cycle->id.'/sessions',
            'body' => ['title' => 'Séance pilote', 'activity_id' => $activity->id, 'activity_version' => $activity->version, 'responsible_enrollment_id' => $mentor->id,
                'exercises' => [['title' => 'Exercice privé', 'instructions' => 'Consigne technique', 'minutes' => 20]], 'report' => 'Compte rendu de séance']];
    }
}
