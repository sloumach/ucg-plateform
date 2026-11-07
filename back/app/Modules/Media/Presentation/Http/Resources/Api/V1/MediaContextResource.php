<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use Illuminate\Http\Request;

final class MediaContextResource extends ApiResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array{id: string, label: string, version: string, is_scrim: bool, visibility: string} $value */
        $value = $this->resource;

        return ['id' => $value['id'], 'label' => $value['label'], 'version' => $value['version'], 'is_scrim' => $value['is_scrim'], 'visibility' => $value['visibility']];
    }
}
