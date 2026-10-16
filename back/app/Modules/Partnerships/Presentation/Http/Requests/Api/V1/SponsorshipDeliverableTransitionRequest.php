<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipCampaignInput as Input;

final class SponsorshipDeliverableTransitionRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return in_array($this->input('target_status'), ['validated', 'promised'], true) ? 'validate' : 'manage';
    }

    /**
     * @return array<string,array<mixed>> */
    public function rules(): array
    {
        return Input::transitionRules();
    }
}
