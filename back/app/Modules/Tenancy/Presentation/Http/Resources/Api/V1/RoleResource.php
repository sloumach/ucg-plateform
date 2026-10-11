<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Http\Request;

/** @mixin OrganizationRole */
final class RoleResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'organization_id' => $this->organization_id, 'key' => $this->key, 'name' => $this->name,
            'permissions' => $this->permissions, 'active' => $this->active, 'privileged' => $this->privileged, 'version' => $this->version];
    }
}
