<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\TeamMembership;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class TeamMembershipResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var TeamMembership $membership */
        $membership = $this->resource;
        $at = $membership->getAttribute('evaluated_at') ?? CarbonImmutable::now();

        return ['id' => $membership->id, 'team_id' => $membership->team_id, 'person_id' => $membership->person_id,
            'pseudonym' => $membership->getAttribute('pseudonym'), 'role_key' => $membership->role_key, 'starts_at' => $membership->starts_at->toIso8601String(),
            'ends_at' => $membership->ends_at?->toIso8601String(), 'status' => $membership->status, 'effective' => $membership->effectiveAt($at),
            'version' => $membership->version];
    }
}
