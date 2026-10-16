<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Media\Domain\Models\AnnouncementComment;
use Illuminate\Http\Request;

final class AnnouncementCommentResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AnnouncementComment $item */
        $item = $this->resource;

        return ['id' => $item->id, 'announcement_id' => $item->announcement_id, 'body' => $item->body,
            'is_mine' => (bool) $item->getAttribute('is_mine'), 'created_at' => $item->created_at->toIso8601String()];
    }
}
