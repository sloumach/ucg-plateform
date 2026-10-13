<?php

namespace App\Modules\Teams\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Http\Request;

final class TeamResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Team $team */
        $team = $this->resource;

        return ['id' => $team->id, 'game_id' => $team->game_id, 'game_key' => $team->game->key, 'game_name' => $team->game->name,
            'game_available' => $team->game->active, 'slug' => $team->slug, 'season' => $team->season,
            'name' => $team->name, 'kind' => $team->kind, 'custom_category' => $team->custom_category,
            'short_name' => $team->short_name, 'public_description' => $team->public_description, 'color' => $team->color,
            'status' => $team->status, 'version' => $team->version, 'publication_status' => 'private'];
    }
}
