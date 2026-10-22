<?php

namespace App\Modules\Academy\Presentation\Http\Resources\Api\V1;

use App\Modules\Academy\Domain\Models\AcademyFeedback;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademyFeedback */
final class AcademyFeedbackResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $self = str_starts_with((string) $request->route('coaching_kind'), 'self');

        return ['id' => $this->id, 'content' => $this->content, 'created_at' => $this->created_at,
            ...($self ? [] : ['version' => $this->version, 'status' => $this->status, 'author_user_id' => $this->author_user_id,
                'audience' => $this->audience, ...($this->audience === 'internal' ? ['justification' => $this->justification] : [])])];
    }
}
