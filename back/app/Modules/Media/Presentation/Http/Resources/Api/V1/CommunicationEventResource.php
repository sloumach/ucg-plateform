<?php

namespace App\Modules\Media\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Media\Domain\Models\CommunicationEvent;
use Illuminate\Http\Request;

final class CommunicationEventResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationEvent $item */
        $item = $this->resource;

        return ['id' => $item->id, 'announcement_id' => $item->announcement_id, 'comment_id' => $item->comment_id,
            'title' => $item->announcement->title, 'kind' => $item->kind, 'read' => $item->getAttribute('read_at') !== null,
            'created_at' => $item->created_at->toIso8601String()];
    }
}
