<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\AvailabilityPeopleDirectory;
use App\Modules\Scheduling\Application\Data\AvailabilityPage;
use App\Modules\Scheduling\Application\Validation\AvailabilityInput;
use App\Modules\Scheduling\Domain\CalendarTime;
use App\Modules\Scheduling\Domain\Contracts\AvailabilityRepository;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\AvailabilityRevision;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final readonly class AvailabilityService
{
    public function __construct(private AvailabilityRepository $records, private AvailabilityPeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $input */
    public function paginate(TenantContext $context, array $input): AvailabilityPage
    {
        $values = AvailabilityInput::validate($this->validator, $input, 'index');
        $viewer = $this->people->viewer($context);
        $collective = (bool) ($values['collective'] ?? false);
        if ($collective) {
            $this->authorization->authorize($context, 'availability.read');
        } else {
            $this->authorization->authorize($context, 'availability.self');
            if (isset($values['person_id'])) {
                throw ValidationException::withMessages(['person_id' => __('availability.collective_required')]);
            }
        }
        $timezone = $values['timezone'] ?? $viewer['timezone'];
        try {
            [$from,$to] = CalendarTime::window($values['from_date'], $values['to_date'], $timezone);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['to_date' => __('calendar.window')]);
        }
        $page = $this->records->paginate($context->organizationId, $collective ? null : $viewer['person_id'], $from, $to, $values);
        $this->decorate($context, array_values($page->items()), $viewer['person_id']);

        return new AvailabilityPage($page, $viewer['person_id'], $viewer['timezone'], $timezone, $from->toIso8601String(), $to->toIso8601String(),
            $this->authorization->allows($context, 'availability.self'), $this->canManage($context));
    }

    /** @param array<string,mixed> $input */
    public function find(TenantContext $context, string $id, array $input): Availability
    {
        AvailabilityInput::validate($this->validator, $input, 'show');
        $viewer = $this->people->viewer($context);
        $record = $this->accessible($context, $id, $viewer['person_id']);
        $this->decorate($context, [$record], $viewer['person_id']);

        return $record;
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,AvailabilityRevision>
     */
    public function history(TenantContext $context, string $id, array $input): LengthAwarePaginator
    {
        $values = AvailabilityInput::validate($this->validator, $input, 'history');
        $viewer = $this->people->viewer($context);
        $record = $this->accessible($context, $id, $viewer['person_id']);
        $this->authorizeWrite($context, $record->person_id, $viewer['person_id']);

        return $this->records->history($record, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 20));
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,array{id:string,label:string}>
     */
    public function people(TenantContext $context, array $input): LengthAwarePaginator
    {
        $values = AvailabilityInput::validate($this->validator, $input, 'people');

        return $this->people->choices($context, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10));
    }

    /** @param array<string,mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ip = null): Availability
    {
        $values = AvailabilityInput::validate($this->validator, $input, $id === null ? 'store' : 'update');
        $saved = $this->database->transaction(function () use ($context, $values, $id, $ip): Availability {
            $this->guard->lock($context);
            $viewer = $this->people->viewer($context);
            $record = $id === null ? null : $this->accessible($context, $id, $viewer['person_id'], true);
            $personId = $record === null ? (isset($values['person_id']) ? strtolower($values['person_id']) : $viewer['person_id']) : $record->person_id;
            if ($personId === null) {
                throw ValidationException::withMessages(['person_id' => __('availability.person_required')]);
            }
            $this->authorizeWrite($context, $personId, $viewer['person_id']);
            if ($record !== null && $record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('calendar.stale'), 'AVAILABILITY_VERSION_STALE');
            }
            if ($record?->cancelled_at !== null) {
                throw new DomainConflictException(__('availability.cancelled'), 'AVAILABILITY_CANCELLED');
            }
            $cancelling = isset($values['cancel']);
            if ($cancelling) {
                if (array_diff(array_keys($values), ['version', 'cancel']) !== []) {
                    throw ValidationException::withMessages(['cancel' => __('availability.cancel_only')]);
                }
                $attributes = ['cancelled_at' => now()];
            } else {
                $this->people->assertAssignable($context, $personId);
                $attributes = $this->attributes($values, $record);
                if ($attributes === []) {
                    throw ValidationException::withMessages(['record' => __('calendar.empty')]);
                }
                $from = $attributes['starts_at'] ?? $record?->starts_at;
                $to = $attributes['ends_at'] ?? $record?->ends_at;
                if ($from === null || $to === null) {
                    throw new InvalidArgumentException('Availability requires a complete period.');
                }
                if ($this->records->overlaps($context->organizationId, $personId, $from, $to, $record?->id)) {
                    throw new DomainConflictException(__('availability.overlap'), 'AVAILABILITY_OVERLAP');
                }
            }
            $action = $cancelling ? 'cancelled' : ($id === null ? 'created' : 'updated');
            $saved = $this->records->save($record, [...($record === null ? ['organization_id' => $context->organizationId, 'person_id' => $personId, 'created_by' => $context->actorUserId] : []),
                ...$attributes, 'version' => (string) Str::uuid(), 'number' => $record === null ? 1 : $record->number + 1], $context->actorUserId, $action);
            $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
                $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['availability_id' => $saved->id, 'availability_action' => $action]));

            return $saved;
        });
        $this->decorate($context, [$saved], $this->people->viewer($context)['person_id']);

        return $saved;
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function attributes(array $values, ?Availability $record): array
    {
        $attributes = array_intersect_key($values, array_flip(['status', 'comment']));
        if (isset($attributes['comment'])) {
            $comment = trim($attributes['comment']);
            $attributes['comment'] = $comment === '' ? null : $comment;
        }
        if ($record === null || array_intersect(array_keys($values), ['starts_at', 'ends_at', 'origin_timezone']) !== []) {
            foreach (['starts_at', 'ends_at', 'origin_timezone'] as $field) {
                if (! isset($values[$field])) {
                    throw ValidationException::withMessages([$field => __('calendar.period_required')]);
                }
            }
            foreach (['starts_at', 'ends_at'] as $field) {
                try {
                    $attributes[$field] = CalendarTime::instant($values[$field], $values['origin_timezone']);
                } catch (InvalidArgumentException) {
                    throw ValidationException::withMessages([$field => __('calendar.offset')]);
                }
            }
            $duration = $attributes['starts_at']->diffInSeconds($attributes['ends_at'], false);
            if ($duration <= 0 || $duration > CalendarTime::MAX_DURATION_SECONDS) {
                throw ValidationException::withMessages(['ends_at' => __('calendar.period')]);
            }
            $attributes['origin_timezone'] = $values['origin_timezone'];
        }

        return $attributes;
    }

    private function accessible(TenantContext $context, string $id, ?string $viewerId, bool $lock = false): Availability
    {
        $read = $this->authorization->allows($context, 'availability.read');
        if (! $read && ! $this->authorization->allows($context, 'availability.self')) {
            throw new AuthorizationException;
        }
        if (! $read && $viewerId === null) {
            throw new ModelNotFoundException;
        }

        return $this->records->find($context->organizationId, $id, $read ? null : $viewerId, $lock);
    }

    private function canManage(TenantContext $context): bool
    {
        return $this->authorization->allows($context, 'availability.read') && $this->authorization->allows($context, 'availability.manage');
    }

    private function authorizeWrite(TenantContext $context, string $personId, ?string $viewerId): void
    {
        if ($personId === $viewerId && $this->authorization->allows($context, 'availability.self')) {
            return;
        }
        $this->authorization->authorize($context, 'availability.read');
        $this->authorization->authorize($context, 'availability.manage');
    }

    /** @param list<Availability> $records */
    private function decorate(TenantContext $context, array $records, ?string $viewerId): void
    {
        $labels = $this->people->labels($context, array_values(array_unique(array_map(fn (Availability $record): string => $record->person_id, $records))));
        $manage = $this->canManage($context);
        $self = $this->authorization->allows($context, 'availability.self');
        foreach ($records as $record) {
            $editable = $manage || ($self && $record->person_id === $viewerId);
            $record->setAttribute('person_label', $labels[$record->person_id] ?? null);
            $record->setAttribute('comment_visible', $editable);
            $record->setAttribute('can_edit', $editable && $record->cancelled_at === null && isset($labels[$record->person_id]));
            $record->setAttribute('can_cancel', $editable && $record->cancelled_at === null);
            $record->setAttribute('can_history', $editable);
        }
    }
}
