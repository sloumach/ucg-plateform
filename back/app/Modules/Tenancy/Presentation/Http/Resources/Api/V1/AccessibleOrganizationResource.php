<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Http\Request;

/** @mixin Organization */
final class AccessibleOrganizationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $owner = $this->owner_user_id === (int) $request->user()?->getAuthIdentifier();
        $membership = $this->memberships->first();

        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug,
            'roles' => $owner ? ['owner'] : ($membership->roles ?? []), 'is_owner' => $owner,
            'membership_id' => $owner ? null : $membership?->id];
    }
}
