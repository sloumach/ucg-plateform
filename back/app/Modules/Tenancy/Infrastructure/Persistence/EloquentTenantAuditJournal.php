<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Domain\Models\OrganizationAuditEvent;
use App\Support\Audit\AuditFilter;
use App\Support\Audit\AuditPage;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditSource;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditTimelineQuery;
use App\Support\Audit\AuditWriteData;

final readonly class EloquentTenantAuditJournal implements TenantAuditJournal
{
    public function __construct(private AuditTimelineQuery $timeline) {}

    public function read(string $organizationId, AuditFilter $filter): AuditPage
    {
        $base = ['id', 'organization_id', 'actor_user_id', 'action', 'request_id', 'occurred_at'];

        return $this->timeline->page([
            new AuditSource('tenancy.lifecycle', 'organization_lifecycle_events', [...$base, 'from_status', 'to_status'], 'actor_user_id', 'organization_id', AuditTarget::Organization, AuditReason::Governance, true, beforeMap: ['status' => 'from_status'], afterMap: ['status' => 'to_status']),
            new AuditSource('tenancy.access', 'organization_access_events', [...$base, 'subject_id', 'before', 'after'], 'actor_user_id', 'subject_id', AuditTarget::Membership, AuditReason::Governance, true, jsonStates: true, targetFromAction: true),
            new AuditSource('tenancy.support', 'organization_support_events', [...$base, 'support_access_id', 'ip_address'], 'actor_user_id', 'support_access_id', AuditTarget::SupportAccess, AuditReason::Support, true),
            new AuditSource('tenancy.audit', 'organization_audit_events', [...$base, 'target_type', 'target_id', 'reason_code', 'ip_address', 'before', 'after'], 'actor_user_id', 'target_id', AuditTarget::Journal, AuditReason::Review, true, targetTypeColumn: 'target_type', jsonStates: true),
        ], $filter, $organizationId);
    }

    public function record(string $organizationId, AuditWriteData $data): void
    {
        $attributes = ['organization_id' => $organizationId, ...$data->attributes()];
        $attributes['before'] = (object) $attributes['before'];
        $attributes['after'] = (object) $attributes['after'];
        (new OrganizationAuditEvent)->forceFill($attributes)->save();
    }
}
