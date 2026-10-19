<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CompetitionOutcomeResultResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['reference_id' => $this->resource['reference_id'], 'status' => $this->resource['status'], 'approved' => $this->resource['approved'], 'outcome' => $this->resource['outcome'], 'series' => $this->resource['series'], 'winners' => $this->resource['winners'], 'observed_at' => $this->resource['observed_at']];
    }
}
