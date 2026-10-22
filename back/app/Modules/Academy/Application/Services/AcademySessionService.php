<?php

namespace App\Modules\Academy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Application\Validation\AcademySessionInput;
use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Contracts\AcademySessionRepository;
use App\Modules\Academy\Domain\Models\AcademySession;
use App\Modules\Academy\Domain\Models\AcademySessionRevision;
use App\Modules\People\Application\Contracts\AcademyPeopleDirectory;
use App\Modules\Scheduling\Application\Contracts\AcademyActivity;
use App\Modules\Scheduling\Application\Contracts\AcademySchedule;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AcademySessionService
{
    public function __construct(private AcademySessionRepository $records, private AcademyRepository $academy,
        private AcademySessionAccess $access, private AcademySessionJournal $journal, private AcademyPeopleDirectory $people,
        private AcademySchedule $schedule, private TenantBusinessGuard $guard, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademySession> */
    public function paginate(TenantContext $context, string $cohort, string $cycle, array $filters): LengthAwarePaginator
    {
        $this->access->authorize($context);
        $filters = AcademySessionInput::validate($this->validator, $filters, 'session', false, false);
        $this->access->cycle($context, $cohort, $cycle);
        $page = $this->records->sessions($context->organizationId, strtolower($cycle), (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 10));
        $this->project($context, array_values($page->items()));

        return $page;
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademySessionRevision> */
    public function history(TenantContext $context, string $cohort, string $cycle, string $session, array $filters): LengthAwarePaginator
    {
        $this->access->authorize($context);
        $filters = AcademySessionInput::validate($this->validator, $filters, 'session', false, false);
        $this->access->cycle($context, $cohort, $cycle);
        $record = $this->records->session($context->organizationId, $cycle, $session);

        return $this->records->history($context->organizationId, $record->id, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 10));
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademyActivity> */
    public function activities(TenantContext $context, string $cohort, string $cycle, array $filters): LengthAwarePaginator
    {
        $this->access->authorize($context);
        $filters = AcademySessionInput::validate($this->validator, $filters, 'activities', false, false);
        $record = $this->access->cycle($context, $cohort, $cycle);

        return $this->schedule->choices($context, $filters['from_date'], $filters['to_date'], $record->timezone,
            (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 10));
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $cohort, string $cycleId, array $input, ?string $id = null, ?string $ip = null): AcademySession
    {
        $this->access->authorize($context, 'academy.manage');
        $values = AcademySessionInput::validate($this->validator, $input, 'session', $id === null);
        $cycle = $this->access->cycle($context, $cohort, $cycleId);
        $initial = $id === null ? null : $this->records->session($context->organizationId, $cycle->id, $id);
        $cancelling = ($values['status'] ?? null) === 'cancelled';
        $mentorId = strtolower($values['responsible_enrollment_id'] ?? $initial->responsible_enrollment_id ?? '');
        $mentor = $cancelling ? null : $this->academy->enrollment($context->organizationId, $cohort, $mentorId);
        if ($mentor !== null && $mentor->cycle_id !== $cycle->id) {
            throw new ModelNotFoundException;
        }
        $account = $mentor === null ? null : $this->people->account($context, $mentor->person_id);
        if (! $cancelling && $account === null) {
            throw new DomainConflictException(__('academy.person_unavailable'), 'ACADEMY_PERSON_UNAVAILABLE');
        }
        try {
            $saved = $this->database->transaction(function () use ($context, $cohort, $cycle, $values, $id, $ip, $account, $cancelling, $mentorId): AcademySession {
                $this->guard->lock($context, $account);
                $this->access->authorize($context, 'academy.manage');
                $cycle = $this->access->cycle($context, $cohort, $cycle->id, true);
                $record = $id === null ? new AcademySession : $this->records->session($context->organizationId, $cycle->id, $id, true);
                if ($record->exists) {
                    $this->access->assertVersion($record, $values['version']);
                }
                if ($cancelling) {
                    if (! $record->exists || array_diff(array_keys($values), ['version', 'status', 'change_reason']) !== []) {
                        throw ValidationException::withMessages(['status' => __('academy_sessions.cancel_only')]);
                    }
                    $attributes = ['status' => 'cancelled'];
                } else {
                    $activityId = strtolower($values['activity_id'] ?? $record->activity_id);
                    $activity = $this->access->activity($context, $activityId, $values['activity_version']);
                    $mentor = $this->access->enrollment($context, $cycle, $mentorId, $activity, true);
                    if ($this->people->account($context, $mentor->person_id, true) !== $account) {
                        throw new DomainConflictException(__('academy.person_unavailable'), 'ACADEMY_PERSON_UNAVAILABLE');
                    }
                    $attributes = $this->attributes($record, $values, $activity, $mentorId);
                }
                $saved = $this->records->saveSession($record, [...(! $record->exists ? ['organization_id' => $context->organizationId, 'cohort_id' => $cycle->cohort_id,
                    'cycle_id' => $cycle->id, 'status' => 'planned', 'exercises' => [], 'report' => null] : []), ...$attributes, 'version' => (string) Str::uuid()]);
                $this->journal->record($context, $saved, $values['change_reason'] ?? null, $ip);

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('academy_sessions.duplicate'), 'ACADEMY_SESSION_ALREADY_LINKED');
        }
        $this->project($context, [$saved]);

        return $saved;
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed> */
    private function attributes(AcademySession $record, array $values, AcademyActivity $activity, string $mentorId): array
    {
        if ($record->exists && $this->records->hasAttendance($record->organization_id, $record->id)
            && ($record->activity_id !== $activity->id || $record->responsible_enrollment_id !== $mentorId
                || ! $record->starts_at->equalTo($activity->startsAt) || ! $record->ends_at->equalTo($activity->endsAt))) {
            throw new DomainConflictException(__('academy_sessions.frozen'), 'ACADEMY_SESSION_ATTENDANCE_FROZEN');
        }
        $status = $values['status'] ?? ($record->exists ? $record->status : 'planned');
        if (($record->exists && $record->status === 'completed' && $status !== 'completed')
            || ($status === 'completed' && $activity->endsAt->isFuture())) {
            throw new DomainConflictException(__('academy_sessions.completion'), 'ACADEMY_SESSION_COMPLETION_INVALID');
        }
        unset($values['version'], $values['change_reason']);
        if (isset($values['title'])) {
            $values['title'] = trim($values['title']);
        }
        if (isset($values['exercises'])) {
            $values['exercises'] = array_map(fn (array $item): array => ['title' => trim($item['title']), 'instructions' => $item['instructions'] ?? null, 'minutes' => (int) $item['minutes']], $values['exercises']);
        }

        return [...$values, 'activity_id' => $activity->id, 'activity_version' => $activity->version,
            'responsible_enrollment_id' => $mentorId, 'starts_at' => $activity->startsAt, 'ends_at' => $activity->endsAt];
    }

    /** @param list<AcademySession> $items */
    private function project(TenantContext $context, array $items): void
    {
        $activities = $this->schedule->available($context, array_values(array_unique(array_map(fn (AcademySession $item): string => $item->activity_id, $items))));
        $labels = $this->people->labels($context, array_values(array_unique(array_map(fn (AcademySession $item): string => $item->responsibleEnrollment->person_id, $items))));
        foreach ($items as $item) {
            $activity = $activities[$item->activity_id] ?? null;
            $item->setAttribute('schedule', $activity);
            $item->setAttribute('schedule_state', $activity === null ? 'unavailable' : ($activity->version === $item->activity_version ? 'current' : 'changed'));
            $item->setAttribute('responsible_label', $labels[$item->responsibleEnrollment->person_id] ?? null);
        }
    }
}
