<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use Illuminate\Http\Request;

/** @mixin OrganizationSupportAccess */
final class SupportAccessResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id, 'actor_user_id' => $this->actor_user_id,
            'reason' => $this->reason, 'actions' => $this->actions, 'status' => $this->effectiveStatus(now()),
            'starts_at' => $this->starts_at->toIso8601String(), 'expires_at' => $this->expires_at->toIso8601String()];
    }
}
