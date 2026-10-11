<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Http\Request;

/** @mixin OrganizationRoleAssignment */
final class RoleAssignmentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id, 'role_id' => $this->role_id, 'role_name' => $this->whenLoaded('role', fn (): string => $this->role->name),
            'user_id' => $this->user_id, 'scope_type' => $this->scope_type, 'scope_id' => $this->scope_id,
            'status' => $this->status, 'starts_at' => $this->starts_at->toIso8601String(), 'ends_at' => $this->ends_at?->toIso8601String(), 'version' => $this->version];
    }
}
