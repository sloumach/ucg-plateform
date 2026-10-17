<?php

namespace App\Modules\Scheduling\Application\Services;

use App\Modules\People\Application\Contracts\CalendarPeopleDirectory;
use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Contracts\CalendarChangeRepository;
use App\Modules\Scheduling\Domain\Contracts\CalendarRepository;
use App\Modules\Teams\Application\Contracts\CalendarTeamsDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Contracts\TenantNotificationRecipientDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantScheduledTask;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\ConnectionInterface;

final readonly class ProcessCalendarChanges implements TenantScheduledTask
{
    public function __construct(private CalendarChangeRepository $changes, private CalendarRepository $calendar,
        private CalendarPeopleDirectory $people, private CalendarTeamsDirectory $teams, private TenantNotificationRecipientDirectory $recipients,
        private TenantBusinessGuard $guard, private ConnectionInterface $database) {}

    public function run(TenantExecutionContext $context, string $runId): void
    {
        foreach ($this->changes->sweep($context->organizationId, 25) as $candidate) {
            $this->database->transaction(function () use ($context, $runId, $candidate): void {
                $issuer = new TenantContext($context->organizationId, $candidate->change->actor_user_id, '', $context->timezone, $context->language, $context->requestId);
                $allowed = true;
                try {
                    $this->guard->lock($issuer);
                } catch (AuthorizationException) {
                    $allowed = false;
                }
                $this->calendar->find($context->organizationId, $candidate->change->activity_id, new CalendarAudience($issuer->actorUserId, null, []), true, true);
                $notice = $this->changes->find($context->organizationId, $candidate->id, true);
                if ($notice->delivered_at !== null) {
                    return;
                }
                $this->changes->update($notice, ['checked_at' => now()]);
                if (! $allowed || $this->recipients->email($context->organizationId, $issuer->actorUserId, $context->requestId, ['calendar.read', 'calendar.write']) === null) {
                    $this->changes->update($notice, ['blocked_reason' => 'issuer_unavailable']);

                    return;
                }
                if ($notice->recipient_id === null || ($this->people->accounts($issuer, [$notice->person_id])[$notice->person_id] ?? null) !== $notice->recipient_id
                    || $this->recipients->email($context->organizationId, $notice->recipient_id, $context->requestId, ['calendar.read']) === null) {
                    $this->changes->update($notice, ['blocked_reason' => 'recipient_unavailable']);

                    return;
                }
                $recipient = new TenantContext($context->organizationId, $notice->recipient_id, '', $context->timezone, $context->language, $context->requestId);
                $viewer = $this->people->viewer($recipient);
                $audience = new CalendarAudience($recipient->actorUserId, $viewer['person_id'], $this->teams->currentIds($recipient));
                if ($viewer['person_id'] !== $notice->person_id || ! $this->calendar->visibleForChange($context->organizationId, $notice->change->activity_id, $audience)) {
                    $this->changes->update($notice, ['blocked_reason' => 'hidden_scope']);

                    return;
                }
                $this->changes->update($notice, ['delivered_at' => now(), 'run_id' => $runId, 'blocked_reason' => null]);
            });
        }
    }
}
