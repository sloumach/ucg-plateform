<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\ExternalCompetition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExternalCompetition */
final class ExternalCompetitionResource extends JsonResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'reference' => $this->reference, 'game_id' => $this->game_id, 'game_key' => $this->game_key, 'game_name' => $this->game_name, 'name' => $this->name, 'organizer' => $this->organizer, 'region' => $this->region, 'season' => $this->season, 'website_url' => $this->website_url, 'contacts' => $this->contacts, 'status' => $this->status, 'version' => $this->version, 'created_at' => $this->created_at->toIso8601String(), 'updated_at' => $this->updated_at->toIso8601String()];
    }
}
