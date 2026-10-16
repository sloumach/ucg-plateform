<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Media\Domain\Models\MediaItem;
use Illuminate\Http\Request;

final class MediaResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MediaItem $item */
        $item = $this->resource;

        return ['id' => $item->id, 'title' => $item->title, 'team_id' => $item->team_id, 'team_label' => $item->getAttribute('team_label'),
            'kind' => $item->kind, 'provider' => $item->provider, 'url' => $item->url, 'status' => $item->status,
            'status_source' => 'manual', 'status_observed_at' => $item->status_observed_at->toIso8601String(),
            'is_scrim' => $item->is_scrim, 'visibility' => $item->visibility, 'effective_visibility' => $item->effectiveVisibility(),
            'publish_at' => $item->publish_at?->toIso8601String(), 'archived_at' => $item->archived_at?->toIso8601String(),
            'version' => $item->version];
    }
}
