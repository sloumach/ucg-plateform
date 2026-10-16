<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipCampaignInput as Input;

final class SponsorshipDeliverableRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return $this->isMethod('GET') ? 'viewAny' : 'manage';
    }

    /**
     * @return array<string,array<mixed>> */
    public function rules(): array
    {
        return $this->isMethod('GET') ? Input::pageRules('deliverables') : Input::deliverableRules($this->isMethod('POST'));
    }
}
