<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\TeamMovement;
use Illuminate\Http\Request;

final class TeamMovementResource extends ApiResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var TeamMovement $event */
        $event = $this->resource;

        return ['id' => $event->id, 'team_id' => $event->team_id, 'team_name' => $event->team->name, 'destination_team_name' => $event->destinationTeam?->name, 'membership_id' => $event->membership_id,
            'destination_team_id' => $event->destination_team_id, 'destination_membership_id' => $event->destination_membership_id,
            'action' => $event->action, 'reason_key' => $event->reason_key, 'actor_user_id' => $event->actor_user_id, 'effective_at' => $event->effective_at->toIso8601String(),
            'recorded_at' => $event->created_at->toIso8601String()];
    }
}
