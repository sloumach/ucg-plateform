<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipCampaignInput as Input;

final class SponsorshipDeliveryRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return 'manage';
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return Input::deliveryRules();
    }
}
