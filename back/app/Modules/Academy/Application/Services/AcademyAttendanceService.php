<?php

namespace App\Modules\Academy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Application\Validation\AcademySessionInput;
use App\Modules\Academy\Domain\Contracts\AcademySessionRepository;
use App\Modules\Academy\Domain\Models\AcademyAttendance;
use App\Modules\People\Application\Contracts\AcademyPeopleDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class AcademyAttendanceService
{
    public function __construct(private AcademySessionRepository $records, private AcademySessionAccess $access,
        private AcademySessionJournal $journal, private AcademyPeopleDirectory $people, private TenantBusinessGuard $guard,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademyAttendance> */
    public function paginate(TenantContext $context, string $cohort, string $cycle, string $sessionId, array $filters): LengthAwarePaginator
    {
        $this->access->authorize($context);
        $filters = AcademySessionInput::validate($this->validator, $filters, 'attendance', false, false);
        $this->access->cycle($context, $cohort, $cycle);
        $session = $this->records->session($context->organizationId, $cycle, $sessionId);
        $page = $this->records->attendances($context->organizationId, $session->id, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 10));
        $labels = $this->people->labels($context, array_values(array_unique(array_map(fn (AcademyAttendance $record): string => $record->enrollment->person_id, array_values($page->items())))));
        foreach ($page->items() as $record) {
            $record->setAttribute('person_label', $labels[$record->enrollment->person_id] ?? null);
        }

        return $page;
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $cohort, string $cycleId, string $sessionId, array $input, ?string $id = null, ?string $ip = null): AcademyAttendance
    {
        $this->access->authorize($context, 'academy.attendance.manage');
        $values = AcademySessionInput::validate($this->validator, $input, 'attendance', $id === null);
        try {
            $saved = $this->database->transaction(function () use ($context, $cohort, $cycleId, $sessionId, $values, $id, $ip): AcademyAttendance {
                $this->guard->lock($context);
                $this->access->authorize($context, 'academy.attendance.manage');
                $cycle = $this->access->cycle($context, $cohort, $cycleId, true);
                $session = $this->records->session($context->organizationId, $cycle->id, $sessionId, true);
                $this->access->assertVersion($session, $session->version);
                $activity = $this->access->activity($context, $session->activity_id, $session->activity_version);
                if ($session->starts_at->isFuture()) {
                    throw new DomainConflictException(__('academy_sessions.not_started'), 'ACADEMY_SESSION_NOT_STARTED');
                }
                $record = $id === null ? new AcademyAttendance : $this->records->attendance($context->organizationId, $session->id, $id, true);
                if ($record->exists && $record->version !== strtolower($values['version'])) {
                    throw new DomainConflictException(__('academy.stale'), 'ACADEMY_ATTENDANCE_VERSION_STALE');
                }
                $enrollment = $this->access->enrollment($context, $cycle, strtolower($values['enrollment_id'] ?? $record->enrollment_id), $activity);
                $attributes = $values;
                unset($attributes['version']);
                $saved = $this->records->saveAttendance($record, [...(! $record->exists ? ['organization_id' => $context->organizationId, 'cycle_id' => $cycle->id,
                    'session_id' => $session->id, 'enrollment_id' => $enrollment->id] : []), ...$attributes,
                    'late_minutes' => $values['status'] === 'late' ? (int) $values['late_minutes'] : null, 'version' => (string) Str::uuid()]);
                $this->journal->record($context, $saved, $values['change_reason'] ?? null, $ip);

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('academy_sessions.attendance_duplicate'), 'ACADEMY_ATTENDANCE_ALREADY_EXISTS');
        }
        $saved->setAttribute('person_label', $this->people->labels($context, [$saved->enrollment->person_id])[$saved->enrollment->person_id] ?? null);

        return $saved;
    }
}
