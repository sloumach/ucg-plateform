<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarInvitationRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantScheduledTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;

final readonly class ProcessCalendarInvitations implements TenantScheduledTask
{
    public function __construct(private CalendarInvitationRepository $records, private CalendarRepository $calendar,
        private CalendarPeopleDirectory $people, private TenantNotificationRecipientDirectory $recipients,
        private TenantBusinessGuard $guard, private ConnectionInterface $database) {}

    public function run(TenantExecutionContext $context, string $runId): void
    {
        foreach ($this->records->sweep($context->organizationId, 25) as $candidate) {
            $this->database->transaction(function () use ($context, $runId, $candidate): void {
                $issuer = new TenantContext($context->organizationId, $candidate->issued_by, '', $context->timezone, $context->language, $context->requestId);
                $allowed = true;
                try {
                    $this->guard->lock($issuer);
                } catch (AuthorizationException) {
                    $allowed = false;
                }
                $this->calendar->find($context->organizationId, $candidate->activity_id, new CalendarAudience($candidate->issued_by, null, []), true, true);
                $record = $this->records->find($context->organizationId, $candidate->id, true);
                $this->records->update($record, ['checked_at' => now()]);
                if (! $record->isCurrent() || $record->response_deadline->lte(now()) || $record->response !== 'pending') {
                    return;
                }
                if (! $allowed || $this->recipients->email($context->organizationId, $record->issued_by, $context->requestId,
                    ['calendar.read', 'calendar.write', 'calendar.invitations.manage']) === null) {
                    $this->records->update($record, ['blocked_reason' => 'issuer_unavailable']);

                    return;
                }
                if ($record->recipient_id === null || ($this->people->accounts($issuer, [$record->person_id])[$record->person_id] ?? null) !== $record->recipient_id
                    || $this->recipients->email($context->organizationId, $record->recipient_id, $context->requestId, ['calendar.read', 'calendar.invitations.respond']) === null) {
                    $this->records->update($record, ['blocked_reason' => 'recipient_unavailable']);

                    return;
                }
                if ($record->sent_at === null) {
                    $this->records->deliver($record, 'invitation', 0, $runId);
                    $this->records->update($record, ['sent_at' => now(), 'blocked_reason' => null]);

                    return;
                }
                // Catch up only the most urgent reached threshold, never a burst of missed reminders.
                $remaining = now()->diffInSeconds($record->response_deadline, false);
                $reached = array_filter($record->reminder_offsets, fn (int $offset): bool => $remaining <= $offset * 60
                    && $record->sent_at->lt($record->response_deadline->subMinutes($offset)));
                if ($reached !== []) {
                    $this->records->deliver($record, 'reminder', min($reached), $runId);
                }
                $this->records->update($record, ['blocked_reason' => null]);
            });
        }
    }
}
