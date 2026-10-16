<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Media\Domain\Models\Announcement;
use Illuminate\Http\Request;

final class AnnouncementResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Announcement $item */
        $item = $this->resource;

        return ['id' => $item->id, 'title' => $item->title, 'body' => $item->body, 'target_type' => $item->target_type, 'target_id' => $item->targetId(),
            'status' => $item->status, 'comments_enabled' => $item->comments_enabled, 'can_comment' => (bool) $item->getAttribute('can_comment'),
            'published_at' => $item->published_at?->toIso8601String(), 'expires_at' => $item->expires_at?->toIso8601String(),
            'withdrawn_at' => $item->withdrawn_at?->toIso8601String(), 'version' => $item->version];
    }
}
