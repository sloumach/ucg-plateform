<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractTransition;
use Illuminate\Http\Request;

final class ContractTransitionResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractTransition $event */
        $event = $this->resource;

        return ['id' => $event->id, 'from_status' => $event->from_status, 'to_status' => $event->to_status,
            'contract_version' => $event->contract_version, 'actor_user_id' => $event->actor_user_id,
            'occurred_at' => $event->occurred_at->toIso8601String()];
    }
}
