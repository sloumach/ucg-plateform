<?php

namespace App\Modules\Academy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\People\Application\Contracts\AcademyPeopleDirectory;
use App\Modules\Scheduling\Application\Contracts\AcademyActivity;
use App\Modules\Scheduling\Application\Contracts\AcademySchedule;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class AcademySessionAccess
{
    public function __construct(private AcademyRepository $academy, private TenantAuthorization $authorization,
        private AcademySchedule $schedule, private AcademyPeopleDirectory $people) {}

    public function authorize(TenantContext $context, ?string $writePermission = null): void
    {
        $this->authorization->authorize($context, 'academy.read');
        if ($writePermission !== null) {
            $this->authorization->authorize($context, $writePermission);
        }
    }

    public function cycle(TenantContext $context, string $cohortId, string $cycleId, bool $write = false): AcademyCycle
    {
        $cohort = $this->academy->cohort($context->organizationId, $cohortId, $write);
        $cycle = $this->academy->cycle($context->organizationId, $cohort->id, $cycleId, $write);
        if ($write && ($cohort->status !== 'active' || $cycle->status !== 'active')) {
            throw new DomainConflictException(__('academy.closed'), 'ACADEMY_CLOSED');
        }

        return $cycle;
    }

    public function activity(TenantContext $context, string $id, string $version): AcademyActivity
    {
        $this->authorization->authorize($context, 'calendar.read');
        $activity = $this->schedule->available($context, [strtolower($id)], true)[strtolower($id)] ?? null;
        if ($activity === null) {
            throw new ModelNotFoundException;
        }
        if ($activity->version !== strtolower($version)) {
            throw new DomainConflictException(__('academy_sessions.schedule_stale'), 'ACADEMY_SCHEDULE_STALE');
        }

        return $activity;
    }

    public function enrollment(TenantContext $context, AcademyCycle $cycle, string $id, AcademyActivity $activity, bool $mentor = false): AcademyEnrollment
    {
        $enrollment = $this->academy->enrollment($context->organizationId, $cycle->cohort_id, $id, true);
        if ($enrollment->cycle_id !== $cycle->id) {
            throw new ModelNotFoundException;
        }
        $from = $activity->startsAt->setTimezone($cycle->timezone)->toDateString();
        $to = $activity->endsAt->subMicrosecond()->setTimezone($cycle->timezone)->toDateString();
        if ($from < $cycle->starts_on || $to > $cycle->ends_on || $enrollment->status !== 'active'
            || $enrollment->starts_on > $from || $enrollment->ends_on < $to || ($mentor && $enrollment->role !== 'mentor')
            || ! isset($this->people->labels($context, [$enrollment->person_id])[$enrollment->person_id])) {
            throw new DomainConflictException(__('academy_sessions.enrollment_unavailable'), 'ACADEMY_ENROLLMENT_UNAVAILABLE');
        }

        return $enrollment;
    }

    public function assertVersion(AcademySession $session, string $version): void
    {
        if ($session->version !== strtolower($version)) {
            throw new DomainConflictException(__('academy.stale'), 'ACADEMY_SESSION_VERSION_STALE');
        }
        if ($session->status === 'cancelled') {
            throw new DomainConflictException(__('academy_sessions.cancelled'), 'ACADEMY_SESSION_CANCELLED');
        }
    }
}
