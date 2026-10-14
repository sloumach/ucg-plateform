<?php

namespace App\Modules\Contracts\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Contracts\Domain\Models\ContractFinancialTerms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ContractFinancialTermsResource extends ApiResource
{
    public function withResponse(Request $request, JsonResponse $response): void
    {
        $response->setStatusCode(200)->header('Cache-Control', 'private, no-store');
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ContractFinancialTerms $terms */
        $terms = $this->resource;

        return ['id' => $terms->id, 'version' => $terms->version, 'terms' => $terms->terms,
            'prepared_by' => $terms->prepared_by, 'updated_at' => $terms->updated_at?->toIso8601String()];
    }
}
