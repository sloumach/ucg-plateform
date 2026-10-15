<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Http\Requests\AuditTimelineRequest;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\OrganizationAuditEvent;

final class TenantAuditRequest extends AuditTimelineRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('viewAny', [OrganizationAuditEvent::class, $context]) ?? false;
    }

    protected function sources(): array
    {
        return ['tenancy.lifecycle', 'tenancy.access', 'tenancy.support', 'tenancy.audit', 'tenancy.sensitive'];
    }
}
