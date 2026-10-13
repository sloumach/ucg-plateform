<?php

namespace App\Modules\Teams\Application\Services;

use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;

final readonly class TeamEligibilityJournal
{
    public function __construct(private TenantAuditJournal $audit) {}

    public function record(TenantContext $context, string $id, string $action, string $status, ?string $lineupId = null): void
    {
        $key = in_array($action, ['preview', 'final'], true) ? 'eligibility_report_id' : 'eligibility_ruleset_id';
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId,
            AuditReason::Domain, $context->requestId, null, [], [$key => $id, 'eligibility_action' => $action, 'eligibility_status' => $status, ...($lineupId === null ? [] : ['team_lineup_id' => $lineupId])]));
    }
}
