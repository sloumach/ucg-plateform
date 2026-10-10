<?php

namespace App\Modules\Tenancy\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Http\Request;

/** @mixin TenantContext */
final class TenantContextResource extends ApiResource
{
    /** @return array<string, int|string> */
    public function toArray(Request $request): array
    {
        return [
            'organization_id' => $this->organizationId,
            'actor_user_id' => $this->actorUserId,
            'slug' => $this->slug,
            'timezone' => $this->timezone,
            'language' => $this->language,
        ];
    }
}
