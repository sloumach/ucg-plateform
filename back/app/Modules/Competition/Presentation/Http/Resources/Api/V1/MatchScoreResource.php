<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MatchScoreResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource;

        return ['match_version' => $data['match_version'], 'rules' => $data['rules'], 'series' => $data['series'],
            ...(array_key_exists('game', $data) ? ['game' => $data['game'] === null ? null : MatchGameResource::make($data['game'])] : [])];
    }
}
