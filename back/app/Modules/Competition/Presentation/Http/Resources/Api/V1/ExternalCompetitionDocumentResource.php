<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\ExternalCompetitionDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExternalCompetitionDocument */
final class ExternalCompetitionDocumentResource extends JsonResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['document_id' => $this->document_id, 'version' => $this->id, 'number' => $this->number, 'kind' => $this->kind, 'title' => $this->title, 'body' => $this->body, 'source_url' => $this->source_url, 'reason' => $this->reason, 'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
