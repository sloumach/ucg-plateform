<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use Illuminate\Http\Request;

/** @mixin OrganizationInvitation */
final class InvitationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id,
            'organization_name' => $this->whenLoaded('organization', fn (): string => $this->organization->name),
            'email' => $this->email, 'roles' => $this->roles, 'status' => $this->effectiveStatus(now())->value,
            'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(),
            'expires_at' => $this->expires_at->toIso8601String()];
    }
}
