<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Application\Validation\CalendarInvitationInput;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarInvitationRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarInvitation;
use App\Modules\Scheduling\Domain\Models\CalendarInvitationDelivery;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class CalendarInvitationService
{
    public function __construct(private CalendarInvitationRepository $records, private CalendarRepository $calendar,
        private CalendarPeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CalendarInvitation>
     */
    public function paginate(TenantContext $context, array $input, ?string $activityId = null): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'calendar.read');
        $filters = CalendarInvitationInput::validate($this->validator, $input, 'index');
        if ($activityId !== null) {
            $this->manage($context);
            $this->activity($context, $activityId);
        }
        $personId = $this->people->viewer($context)['person_id'];
        $canRespond = $this->authorization->allows($context, 'calendar.invitations.respond');
        $page = $this->records->paginate($context->organizationId, $filters, $activityId, $personId, $context->actorUserId);
        $labels = $this->people->labels($context, array_values(array_unique(array_map(fn (CalendarInvitation $i): string => $i->person_id, $page->items()))));
        foreach ($page->items() as $record) {
            $record->setAttribute('person_label', $labels[$record->person_id] ?? null);
            $record->setAttribute('can_respond', $this->canRespond($context, $record, $personId, $canRespond));
        }

        return $page;
    }

    /** @param array<string,mixed> $input */
    public function issue(TenantContext $context, string $activityId, array $input, ?string $ip): void
    {
        $this->manage($context);
        $values = CalendarInvitationInput::validate($this->validator, $input, 'issue');
        $this->database->transaction(function () use ($context, $activityId, $values, $ip): void {
            $this->guard->lock($context);
            $this->manage($context);
            $activity = $this->activity($context, $activityId, true);
            if ($activity->version !== strtolower($values['activity_version'])) {
                throw new DomainConflictException(__('calendar.stale'), 'CALENDAR_VERSION_STALE');
            }
            if ($activity->archived_at !== null) {
                throw new DomainConflictException(__('calendar.archived'), 'CALENDAR_ARCHIVED');
            }
            $deadline = CarbonImmutable::parse($values['response_deadline'])->utc();
            if ($deadline->lte(now()) || $deadline->gt($activity->starts_at)) {
                throw ValidationException::withMessages(['response_deadline' => __('calendar.invitation_deadline')]);
            }
            $ids = array_values($activity->participants->pluck('person_id')->all());
            if ($ids === []) {
                throw ValidationException::withMessages(['record' => __('calendar.invitation_people')]);
            }
            $this->people->assertAssignable($context, $ids);
            $accounts = $this->people->accounts($context, $ids);
            if ($this->records->exists($context->organizationId, $activity->id, $activity->version)) {
                throw new DomainConflictException(__('calendar.invitation_duplicate'), 'CALENDAR_INVITATIONS_ALREADY_ISSUED');
            }
            $offsets = $values['reminder_offsets'];
            sort($offsets);
            foreach ($ids as $personId) {
                $this->records->create(['organization_id' => $context->organizationId, 'activity_id' => $activity->id,
                    'activity_version' => $activity->version, 'person_id' => $personId,
                    'recipient_id' => $accounts[$personId] ?? null, 'issued_by' => $context->actorUserId,
                    'response_deadline' => $deadline, 'reminder_offsets' => $offsets, 'response' => 'pending', 'version' => (string) Str::uuid()]);
            }
            $this->audit($context, $activity->id, 'invitations_issued', $ip);
        });
    }

    /** @param array<string,mixed> $input */
    public function respond(TenantContext $context, string $id, array $input, ?string $ip): CalendarInvitation
    {
        $this->authorization->authorize($context, 'calendar.read');
        $this->authorization->authorize($context, 'calendar.invitations.respond');
        $values = CalendarInvitationInput::validate($this->validator, $input, 'respond');

        return $this->database->transaction(function () use ($context, $id, $values, $ip): CalendarInvitation {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'calendar.read');
            $this->authorization->authorize($context, 'calendar.invitations.respond');
            $snapshot = $this->records->find($context->organizationId, $id);
            $this->activity($context, $snapshot->activity_id, true);
            $record = $this->records->find($context->organizationId, $id, true);
            $personId = $this->people->viewer($context)['person_id'];
            if ($record->recipient_id !== $context->actorUserId || $record->person_id !== $personId || $record->sent_at === null) {
                throw new ModelNotFoundException;
            }
            if (! $record->isCurrent()) {
                throw new DomainConflictException(__('calendar.invitation_obsolete'), 'CALENDAR_INVITATION_OBSOLETE');
            }
            if ($record->response_deadline->lte(now())) {
                throw new DomainConflictException(__('calendar.invitation_expired'), 'CALENDAR_INVITATION_EXPIRED');
            }
            if ($record->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('calendar.invitation_stale'), 'CALENDAR_INVITATION_STALE');
            }
            if ($record->response !== $values['response']) {
                $this->records->update($record, ['response' => $values['response'], 'responded_at' => $values['response'] === 'pending' ? null : now(), 'version' => (string) Str::uuid()]);
                $this->audit($context, $record->id, 'invitation_responded', $ip);
            }
            $record->setAttribute('can_respond', true);

            return $record;
        });
    }

    /** @param array<string,mixed> $input
     * @return LengthAwarePaginator<int,CalendarInvitationDelivery>
     */
    public function history(TenantContext $context, string $id, array $input): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'calendar.read');
        $filters = CalendarInvitationInput::validate($this->validator, $input, 'history');
        $record = $this->records->find($context->organizationId, $id);
        if (! $this->authorization->allows($context, 'calendar.write') || ! $this->authorization->allows($context, 'calendar.invitations.manage')) {
            if ($record->recipient_id !== $context->actorUserId || $record->person_id !== $this->people->viewer($context)['person_id']
                || $record->sent_at === null || ! $record->isCurrent()) {
                throw new ModelNotFoundException;
            }
        }

        return $this->records->history($record, $filters);
    }

    private function canRespond(TenantContext $context, CalendarInvitation $record, ?string $personId, bool $allowed): bool
    {
        return $record->recipient_id === $context->actorUserId && $record->person_id === $personId && $record->sent_at !== null
            && $record->isCurrent() && $record->response_deadline->gt(now()) && $allowed;
    }

    private function activity(TenantContext $context, string $id, bool $lock = false): CalendarActivity
    {
        return $this->calendar->find($context->organizationId, $id, new CalendarAudience($context->actorUserId, null, []), true, $lock);
    }

    private function manage(TenantContext $context): void
    {
        foreach (['calendar.read', 'calendar.write', 'calendar.invitations.manage'] as $permission) {
            $this->authorization->authorize($context, $permission);
        }
    }

    private function audit(TenantContext $context, string $id, string $action, ?string $ip): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, $ip, [], ['calendar_reference_id' => $id, 'calendar_action' => $action]));
    }
}
