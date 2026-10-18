<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\MatchNotebookRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MatchNotebookRevision */
final class MatchNotebookResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['entry_id' => $this->entry_id, 'version' => $this->id, 'number' => $this->number, 'kind' => $this->kind, 'audience' => $this->audience,
            'title' => $this->title, 'body' => $this->body, 'vod_url' => $this->vod_url, 'timestamp_seconds' => $this->timestamp_seconds,
            'state' => $this->state, 'due_at' => $this->due_at?->toIso8601String(), 'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
