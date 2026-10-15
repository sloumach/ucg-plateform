<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractReminderPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractReminderPlanResource extends ApiResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200)->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractReminderPlan $plan */
        $plan = $this->resource;

        return ['id' => $plan->id, 'version' => $plan->version, 'enabled' => $plan->enabled,
            'notice_on' => $plan->notice_on?->format('Y-m-d'), 'renewal_on' => $plan->renewal_on?->format('Y-m-d'),
            'offsets' => $plan->offsets, 'channels' => $plan->channels, 'checked_at' => $plan->checked_at?->toIso8601String()];
    }
}
