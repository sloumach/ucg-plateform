<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipCampaignInput as Input;

final class SponsorshipCampaignRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return $this->isMethod('GET') ? 'viewAny' : 'manage';
    }

    /**
     * @return array<string,array<mixed>> */
    public function rules(): array
    {
        return $this->isMethod('GET') ? Input::pageRules(str_ends_with((string) $this->route()?->getName(), '.history') ? 'history' : (str_ends_with((string) $this->route()?->getName(), '.dashboard') ? 'dashboard' : 'campaigns')) : Input::campaignRules($this->isMethod('POST'));
    }
}
