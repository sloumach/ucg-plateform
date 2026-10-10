<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Application\Data\ActiveOrganizationData;
use Illuminate\Http\Request;

/** @mixin ActiveOrganizationData */
final class ActiveOrganizationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $context = $this->context;

        return ['context' => $context === null ? null : ['organization_id' => $context->organizationId,
            'name' => $context->organizationName, 'slug' => $context->slug, 'timezone' => $context->timezone,
            'language' => $context->language, 'roles' => $context->roles, 'permissions' => $context->permissions(),
            'is_owner' => $context->isOwner, 'actor_user_id' => $context->actorUserId, 'membership_id' => $context->membershipId],
            'revision' => $this->revision, 'confirmed' => $this->confirmed];
    }
}
