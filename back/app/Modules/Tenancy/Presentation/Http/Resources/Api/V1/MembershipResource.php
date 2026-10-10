<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Illuminate\Http\Request;

/** @mixin OrganizationMembership */
final class MembershipResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id, 'user_id' => $this->user_id,
            'roles' => $this->roles, 'status' => $this->status->value, 'effective' => $this->isEffective(now()),
            'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String()];
    }
}
