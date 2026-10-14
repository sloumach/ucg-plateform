<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use Illuminate\Http\Request;

final class ExternalOpponentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ExternalOpponent $opponent */
        $opponent = $this->resource;

        return ['id' => $opponent->id, 'key' => $opponent->key, 'kind' => $opponent->kind,
            'name' => $opponent->name, 'short_name' => $opponent->short_name, 'organization_name' => $opponent->organization_name,
            'game_id' => $opponent->game_id, 'game_key' => $opponent->game?->key, 'game_name' => $opponent->game?->name,
            'source_label' => $opponent->source_label, 'source_url' => $opponent->source_url,
            'observed_at' => $opponent->observed_at->toIso8601String(), 'status' => $opponent->status,
            'version' => $opponent->version, 'publication_status' => 'private'];
    }
}
