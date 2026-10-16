<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreementEvent;
use Illuminate\Http\Request;

final class SponsorshipHistoryResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SponsorshipAgreementEvent $record */
        $record = $this->resource;

        return ['id' => $record->id, 'action' => $record->action, 'from_status' => $record->from_status,
            'to_status' => $record->to_status, 'actor_user_id' => $record->actor_user_id,
            'agreement_version' => $record->agreement_version, 'occurred_at' => $record->occurred_at->toIso8601String()];
    }
}
