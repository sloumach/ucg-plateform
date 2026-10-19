<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CompetitionRegistrationRevision */
final class CompetitionRegistrationResource extends JsonResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['version' => $this->id, 'number' => $this->number, 'payload' => $this->payload, 'reason' => $this->reason, 'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->created_at->toIso8601String(), 'summary' => CompetitionRegistrationService::summary($this->payload)];
    }
}
