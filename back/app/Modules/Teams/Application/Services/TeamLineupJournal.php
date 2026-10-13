<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;

final readonly class TeamLineupJournal
{
    public function __construct(private TenantAuditJournal $audit) {}

    /** @param array<string,mixed> $before */
    public function record(TenantContext $context, TeamLineup $lineup, string $action, array $before = []): void
    {
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization,
            $context->organizationId, AuditReason::Domain, $context->requestId, null, $before, [...$this->state($lineup), 'team_lineup_action' => $action]));
    }

    /** @return array<string,mixed> */
    public function state(TeamLineup $lineup): array
    {
        return ['team_lineup_id' => $lineup->id, 'team_id' => $lineup->team_id, 'team_lineup_status' => $lineup->status,
            'team_lineup_effective_at' => $lineup->effective_at->toIso8601String(), 'version' => $lineup->version,
            'team_lineup_membership_ids' => $lineup->entries->pluck('membership_id')->all()];
    }
}
