<?php

namespace App\Modules\Academy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Application\Validation\AcademyInput;
use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyEnrollment;
use App\Modules\Academy\Domain\Models\AcademyRevision;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\People\Application\Contracts\AcademyPeopleDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AcademyService
{
    public function __construct(private AcademyRepository $records, private AcademyPeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Cohort>|LengthAwarePaginator<int, AcademyCycle>|LengthAwarePaginator<int, AcademyEnrollment>|LengthAwarePaginator<int, AcademyRevision> */
    public function paginate(TenantContext $context, string $kind, array $filters, ?string $cohortId = null): LengthAwarePaginator
    {
        $this->authorize($context);
        $values = AcademyInput::validate($this->validator, $filters, $kind, false);
        if ($cohortId !== null) {
            $this->records->cohort($context->organizationId, $cohortId);
        }

        $page = $this->records->paginate($context->organizationId, $kind, $values, $cohortId);
        if ($kind === 'enrollment') {
            $ids = [];
            foreach ($page->items() as $item) {
                if ($item instanceof AcademyEnrollment) {
                    $ids[] = $item->person_id;
                }
            }
            $labels = $this->people->labels($context, array_values(array_unique($ids)));
            foreach ($page->items() as $item) {
                if ($item instanceof AcademyEnrollment) {
                    $item->setAttribute('person_label', $labels[$item->person_id] ?? null);
                }
            }
        }

        return $page;
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $kind, array $input, ?string $cohortId = null, ?string $id = null, ?string $ip = null): Cohort|AcademyCycle|AcademyEnrollment
    {
        $this->authorize($context, true);
        $values = AcademyInput::validate($this->validator, $input, $kind, true, $id === null);
        foreach (['cycle_id', 'person_id'] as $field) {
            if (isset($values[$field])) {
                $values[$field] = strtolower($values[$field]);
            }
        }
        // Resolve before taking tenant/resource locks, then recheck under the person lock.
        $account = $kind === 'enrollment' && $id === null ? $this->people->account($context, $values['person_id']) : null;

        return $this->database->transaction(function () use ($context, $kind, $values, $cohortId, $id, $ip, $account): Cohort|AcademyCycle|AcademyEnrollment {
            $this->guard->lock($context, $account);
            $this->authorize($context, true);
            $cohort = $cohortId === null ? null : $this->records->cohort($context->organizationId, $cohortId, true);
            $record = match ($kind) {
                'cohort' => $id === null ? new Cohort : $this->records->cohort($context->organizationId, $id, true),
                'cycle' => $id === null ? new AcademyCycle : $this->records->cycle($context->organizationId, (string) $cohortId, $id, true),
                'enrollment' => $id === null ? new AcademyEnrollment : $this->records->enrollment($context->organizationId, (string) $cohortId, $id, true),
                default => throw new \InvalidArgumentException('Unknown academy resource.'),
            };
            if ($id !== null && $record->version !== $values['version']) {
                throw new DomainConflictException(__('academy.stale'), 'ACADEMY_VERSION_STALE');
            }
            if ($cohort?->status === 'archived' || ($record->exists && in_array($record->status, ['archived', 'withdrawn', 'completed'], true))) {
                throw new DomainConflictException(__('academy.closed'), 'ACADEMY_CLOSED');
            }
            $attributes = $values;
            unset($attributes['version']);
            if ($attributes === []) {
                throw ValidationException::withMessages(['academy' => __('academy.empty')]);
            }
            if (isset($attributes['name'])) {
                $attributes['name'] = trim($attributes['name']);
            }
            if ($record instanceof AcademyCycle) {
                if (isset($attributes['timetable'])) {
                    $attributes['timetable'] = array_map(fn (array $slot): array => [...$slot, 'day' => (int) $slot['day']], $attributes['timetable']);
                }
                $this->validateCycle($context, $record, $attributes);
            }
            if ($record instanceof AcademyEnrollment && ! $record->exists) {
                $cycle = $this->records->cycle($context->organizationId, (string) $cohortId, $values['cycle_id'], true);
                if ($cycle->status !== 'active') {
                    throw new DomainConflictException(__('academy.closed'), 'ACADEMY_CLOSED');
                }
                if ($values['starts_on'] < $cycle->starts_on || $values['ends_on'] > $cycle->ends_on) {
                    throw ValidationException::withMessages(['ends_on' => __('academy.outside_cycle')]);
                }
                $currentAccount = $this->people->account($context, $values['person_id'], true);
                if ($currentAccount !== $account || ($values['role'] === 'mentor' && $currentAccount === null)) {
                    throw new DomainConflictException(__('academy.person_unavailable'), 'ACADEMY_PERSON_UNAVAILABLE');
                }
                if ($this->records->overlaps($context->organizationId, $cycle->id, $values['person_id'], $values['starts_on'], $values['ends_on'], null)) {
                    throw new DomainConflictException(__('academy.overlap'), 'ACADEMY_ENROLLMENT_OVERLAP');
                }
            }
            $saved = $this->records->save($record, [
                ...(! $record->exists ? ['organization_id' => $context->organizationId, 'status' => 'active', ...($cohortId === null ? [] : ['cohort_id' => strtolower($cohortId)])] : []),
                ...$attributes, 'version' => (string) Str::uuid(),
            ]);
            $this->records->revise(['organization_id' => $context->organizationId, 'cohort_id' => $saved instanceof Cohort ? $saved->id : $saved->cohort_id,
                'kind' => $kind, 'resource_id' => $saved->id, 'actor_user_id' => $context->actorUserId,
                'snapshot' => $saved->attributesToArray(), 'recorded_at' => now()]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ip,
                [], ['academy_resource_id' => $saved->id, 'academy_kind' => $kind, 'version' => $saved->version]));

            return $saved;
        });
    }

    private function authorize(TenantContext $context, bool $write = false): void
    {
        $this->authorization->authorize($context, 'academy.read');
        if ($write) {
            $this->authorization->authorize($context, 'academy.manage');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function validateCycle(TenantContext $context, AcademyCycle $cycle, array $attributes): void
    {
        $starts = $attributes['starts_on'] ?? $cycle->starts_on;
        $ends = $attributes['ends_on'] ?? $cycle->ends_on;
        if ($starts > $ends) {
            throw ValidationException::withMessages(['ends_on' => __('academy.dates_invalid')]);
        }
        if ($cycle->exists && (isset($attributes['starts_on']) || isset($attributes['ends_on']))
            && ($starts !== $cycle->starts_on || $ends !== $cycle->ends_on)
            && $this->records->hasEnrollments($context->organizationId, $cycle->id)) {
            throw new DomainConflictException(__('academy.dates_locked'), 'ACADEMY_CYCLE_DATES_LOCKED');
        }
        AcademyInput::assertTimetable($attributes['timetable'] ?? $cycle->timetable);
    }
}
