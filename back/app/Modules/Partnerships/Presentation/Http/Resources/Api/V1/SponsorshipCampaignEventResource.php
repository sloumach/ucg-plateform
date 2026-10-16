<?php

namespace App\Modules\Partnerships\Presentation\Http\Resources\Api\V1;

use App\Http\Api\ApiResource;
use App\Modules\Partnerships\Domain\Models\SponsorshipCampaignEvent;
use Illuminate\Http\Request;

/** @mixin SponsorshipCampaignEvent */
final class SponsorshipCampaignEventResource extends ApiResource
{
    /**
     * @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'campaign_id' => $this->campaign_id, 'deliverable_id' => $this->deliverable_id,
            'action' => $this->action, 'from_status' => $this->from_status, 'to_status' => $this->to_status, 'record_version' => $this->record_version,
            'actor_user_id' => $this->actor_user_id, 'occurred_at' => $this->occurred_at->toIso8601String()];
    }
}
