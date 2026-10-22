<?php

namespace App\Modules\Academy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Academy\Application\Validation\AcademyCoachingInput;
use App\Modules\Academy\Domain\Contracts\AcademyCoachingRepository;
use App\Modules\Academy\Domain\Contracts\AcademyRepository;
use App\Modules\Academy\Domain\Models\AcademyCoachingRevision;
use App\Modules\Academy\Domain\Models\AcademyCycle;
use App\Modules\Academy\Domain\Models\AcademyFeedback;
use App\Modules\Academy\Domain\Models\AcademyObjective;
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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AcademyCoachingService
{
    public function __construct(private AcademyCoachingRepository $records, private AcademyRepository $academy,
        private AcademySessionAccess $access, private AcademyPeopleDirectory $people, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    public function authorize(TenantContext $context, string $kind, bool $write): void
    {
        if (str_starts_with($kind, 'self')) {
            if ($write) {
                throw new \LogicException('Personal coaching is read-only.');
            }
            $this->authorization->authorize($context, 'academy.self.read');

            return;
        }
        $this->authorization->authorize($context, 'academy.read');
        $permission = match ($kind) {
            'internal' => 'academy.notes.manage', 'shared' => $write ? 'academy.feedback.manage' : null,
            default => $write ? 'academy.manage' : null,
        };
        if ($permission !== null) {
            $this->authorization->authorize($context, $permission);
        }
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, AcademyObjective>|LengthAwarePaginator<int, AcademyFeedback>|LengthAwarePaginator<int, AcademyCoachingRevision> */
    public function paginate(TenantContext $context, string $kind, array $filters, ?string $cohort, ?string $cycle, ?string $objective): LengthAwarePaginator
    {
        $this->authorize($context, $kind, false);
        $filters = AcademyCoachingInput::validate($this->validator, $filters, $kind, false, false);
        $self = str_starts_with($kind, 'self');
        $person = $self ? $this->people->viewer($context) : null;
        if (! $self) {
            $this->access->cycle($context, (string) $cohort, (string) $cycle);
        }
        if ($objective === null) {
            $page = $this->records->objectives($context->organizationId, $cycle, $filters, $person, $self);
            if (! $self) {
                $this->labels($context, array_values($page->items()));
            }

            return $page;
        }
        $record = $this->records->objective($context->organizationId, $objective, $cycle, false, $person, $self);
        if ($kind === 'history') {
            return $this->records->history($context->organizationId, $record->id, $filters, $this->authorization->allows($context, 'academy.notes.manage'));
        }

        return $this->records->feedback($context->organizationId, $record->id, $kind === 'internal' ? 'internal' : 'shared', $filters, $self);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, string $kind, string $cohort, string $cycleId, ?string $objectiveId, ?string $feedbackId, array $input, ?string $ip): AcademyObjective|AcademyFeedback
    {
        $this->authorize($context, $kind, true);
        $id = $kind === 'objective' ? $objectiveId : $feedbackId;
        $values = AcademyCoachingInput::validate($this->validator, $input, $kind, true, $id === null);
        $closing = $kind === 'objective' && in_array($values['status'] ?? null, ['completed', 'cancelled'], true);
        if ($closing && array_diff(array_keys($values), ['version', 'status', 'change_reason']) !== []) {
            throw ValidationException::withMessages(['status' => __('coaching.close_only')]);
        }
        $initial = $objectiveId === null ? null : $this->records->objective($context->organizationId, $objectiveId, $cycleId);
        $mentorId = $kind === 'objective' && ! $closing ? strtolower($values['responsible_enrollment_id'] ?? $initial->responsible_enrollment_id ?? '') : null;
        $mentor = $mentorId === null ? null : $this->academy->enrollment($context->organizationId, $cohort, $mentorId);
        if ($mentor !== null && $mentor->cycle_id !== $cycleId) {
            throw new ModelNotFoundException;
        }
        $account = $mentor === null ? null : $this->people->account($context, $mentor->person_id);
        if ($mentor !== null && $account === null) {
            throw new DomainConflictException(__('academy.person_unavailable'), 'ACADEMY_PERSON_UNAVAILABLE');
        }

        return $this->database->transaction(function () use ($context, $kind, $cohort, $cycleId, $objectiveId, $feedbackId, $values, $id, $ip, $mentorId, $account, $closing): AcademyObjective|AcademyFeedback {
            $this->guard->lock($context, $account);
            $this->authorize($context, $kind, true);
            $cycle = $this->access->cycle($context, $cohort, $cycleId, true);
            $objective = $objectiveId === null ? new AcademyObjective : $this->records->objective($context->organizationId, $objectiveId, $cycle->id, true);
            $record = $kind === 'objective' ? $objective : ($feedbackId === null ? new AcademyFeedback : $this->records->feedbackRecord($context->organizationId, $objective->id, $feedbackId, $kind));
            if ($id !== null && $record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('academy.stale'), 'ACADEMY_COACHING_VERSION_STALE');
            }
            if (($record->exists && $record->status !== 'active') || ($kind !== 'objective' && $objective->status === 'cancelled')) {
                throw new DomainConflictException(__('coaching.closed'), 'ACADEMY_COACHING_CLOSED');
            }
            $attributes = $values;
            unset($attributes['version'], $attributes['change_reason']);
            if ($attributes === []) {
                throw new DomainConflictException(__('academy.empty'), 'ACADEMY_COACHING_EMPTY');
            }
            if ($closing) {
                $attributes = ['status' => $values['status']];
            } elseif ($kind === 'objective') {
                $due = $values['due_on'] ?? $objective->due_on;
                $this->enrollment($context, $cycle, (string) $mentorId, $due, true);
                $mentor = $this->academy->enrollment($context->organizationId, $cohort, (string) $mentorId, true);
                if ($this->people->account($context, $mentor->person_id, true) !== $account) {
                    throw new DomainConflictException(__('academy.person_unavailable'), 'ACADEMY_PERSON_UNAVAILABLE');
                }
                $target = $objective->exists ? $objective->target_enrollment_id : ($values['target_enrollment_id'] === null ? null : strtolower($values['target_enrollment_id']));
                if ($target !== null) {
                    $this->enrollment($context, $cycle, $target, $due, false);
                }
                $attributes = [...$attributes, 'responsible_enrollment_id' => $mentorId,
                    ...(! $objective->exists ? ['organization_id' => $context->organizationId, 'cohort_id' => $cohort, 'cycle_id' => $cycle->id, 'target_enrollment_id' => $target, 'description' => $values['description'] ?? null, 'status' => 'active'] : [])];
            } elseif (! $record->exists) {
                $attributes = [...$attributes, 'organization_id' => $context->organizationId, 'objective_id' => $objective->id,
                    'cycle_id' => $cycle->id, 'audience' => $kind, 'author_user_id' => $context->actorUserId, 'status' => 'active'];
            }
            $saved = $this->records->save($record, [...$attributes, 'version' => (string) Str::uuid()]);
            $this->records->revise(['organization_id' => $context->organizationId, 'objective_id' => $kind === 'objective' ? $saved->id : $objective->id,
                'kind' => $kind === 'objective' ? 'objective' : 'feedback', 'resource_id' => $saved->id,
                'audience' => $kind === 'internal' ? 'internal' : 'shared', 'actor_user_id' => $context->actorUserId,
                'snapshot' => ['fields' => $saved->getAttributes(), 'change_reason' => $values['change_reason'] ?? null], 'recorded_at' => now()]);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['academy_resource_id' => $saved->id, 'academy_kind' => 'coaching', 'version' => $saved->version]));
            if ($saved instanceof AcademyObjective) {
                $saved->load(['responsible', 'target']);
                $this->labels($context, [$saved]);
            }

            return $saved;
        });
    }

    private function enrollment(TenantContext $context, AcademyCycle $cycle, string $id, string $due, bool $mentor): void
    {
        $record = $this->academy->enrollment($context->organizationId, $cycle->cohort_id, $id, true);
        if ($record->cycle_id !== $cycle->id) {
            throw new ModelNotFoundException;
        }
        if ($due < $cycle->starts_on || $due > $cycle->ends_on || $record->status !== 'active'
            || $record->role !== ($mentor ? 'mentor' : 'student') || $record->starts_on > $due || $record->ends_on < $due
            || ! isset($this->people->labels($context, [$record->person_id])[$record->person_id])) {
            throw new DomainConflictException(__('coaching.enrollment'), 'ACADEMY_OBJECTIVE_ENROLLMENT_UNAVAILABLE');
        }
    }

    /** @param list<AcademyObjective> $items */
    private function labels(TenantContext $context, array $items): void
    {
        $ids = [];
        foreach ($items as $item) {
            $ids[] = $item->responsible->person_id;
            if ($item->target !== null) {
                $ids[] = $item->target->person_id;
            }
        }
        // Two distinct people per objective; resolve each bounded batch once.
        $labels = [];
        foreach (array_chunk(array_values(array_unique($ids)), 100) as $batch) {
            $labels += $this->people->labels($context, $batch);
        }
        foreach ($items as $item) {
            $item->setAttribute('responsible_label', $labels[$item->responsible->person_id] ?? null);
            $item->setAttribute('target_label', $item->target === null ? null : ($labels[$item->target->person_id] ?? null));
        }
    }
}
