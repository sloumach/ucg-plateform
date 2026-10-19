<?php

namespace App\Modules\Competition\Presentation\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \ArrayObject<string,mixed> */
final class CompetitionOutcomeResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['version' => $this['version'], 'number' => $this['number'], 'stream' => $this['stream'], 'payload' => $this['payload'], 'matches' => $this['matches'], 'reason' => $this['reason'], 'occurred_at' => $this['occurred_at']];
    }
}
