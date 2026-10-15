<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractReminderDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractReminderDeliveryResource extends ApiResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200)->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractReminderDelivery $delivery */
        $delivery = $this->resource;

        return ['id' => $delivery->id, 'contract_id' => $delivery->contract_id, 'version' => $delivery->version,
            'kind' => $delivery->kind, 'due_on' => $delivery->due_on->format('Y-m-d'), 'offset_days' => $delivery->offset_days,
            'in_app_status' => $delivery->in_app_status, 'email_status' => $delivery->email_status,
            'reason' => $delivery->reason, 'attempts' => $delivery->attempts, 'created_at' => $delivery->created_at?->toIso8601String(),
            'email_sent_at' => $delivery->email_sent_at?->toIso8601String()];
    }
}
