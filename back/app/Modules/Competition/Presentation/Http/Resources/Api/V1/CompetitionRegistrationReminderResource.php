<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Domain\Models\CompetitionRegistrationReminder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompetitionRegistrationReminder */
final class CompetitionRegistrationReminderResource extends JsonResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'item_id' => $this->item_id, 'stage' => $this->stage, 'payload' => $this->payload, 'occurred_at' => $this->created_at->toIso8601String()];
    }
}
