<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;

final readonly class TeamMembershipJournal
{
    public function __construct(private TeamMembershipRepository $records, private TenantAuditJournal $audit) {}

    /** @param array<string,mixed> $before */
    public function record(TenantContext $context, TeamMembership $membership, string $action, string $reason, CarbonImmutable $at, array $before = [], ?TeamMembership $destination = null): void
    {
        $movement = $this->records->record(['organization_id' => $context->organizationId, 'team_id' => $membership->team_id, 'membership_id' => $membership->id,
            'destination_team_id' => $destination?->team_id, 'destination_membership_id' => $destination?->id, 'action' => $action, 'reason_key' => $reason,
            'actor_user_id' => $context->actorUserId, 'effective_at' => $at, 'request_id' => $context->requestId]);
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, null, $before, [...$this->state($membership),
                'team_movement_id' => $movement->id, 'team_movement_action' => $action, 'team_movement_reason' => $reason]));
    }

    /** @return array<string,mixed> */
    public function state(TeamMembership $membership): array
    {
        return ['team_membership_id' => $membership->id, 'team_id' => $membership->team_id, 'person_id' => $membership->person_id,
            'team_role_key' => $membership->role_key, 'team_membership_status' => $membership->status,
            'starts_at' => $membership->starts_at->toIso8601String(), 'ends_at' => $membership->ends_at?->toIso8601String(), 'version' => $membership->version];
    }
}
