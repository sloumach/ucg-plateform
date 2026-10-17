<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MatchReferenceResource extends JsonResource
{
    /** @return array{id:string,label:string} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id'], 'label' => $this->resource['label']];
    }
}
