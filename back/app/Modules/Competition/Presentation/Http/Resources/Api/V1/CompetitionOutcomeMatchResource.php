<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompetitionOutcomeMatchResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'version' => $this->resource['version'], 'title' => $this->resource['title'], 'status' => $this->resource['status']];
    }
}
