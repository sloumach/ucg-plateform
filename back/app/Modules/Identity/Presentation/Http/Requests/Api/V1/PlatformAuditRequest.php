<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use App\Http\Requests\AuditTimelineRequest;
use App\Modules\Identity\Domain\Models\IdentityAuditEvent;

final class PlatformAuditRequest extends AuditTimelineRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', IdentityAuditEvent::class) ?? false;
    }

    protected function sources(): array
    {
        return ['identity.security', 'identity.platform_roles', 'identity.audit'];
    }
}
