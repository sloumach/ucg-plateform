<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;

final class SponsorshipExposureRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return $this->isMethod('GET') ? 'viewAny' : 'manage';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return $this->isMethod('GET') ? Input::pageRules('exposures') : Input::exposureRules($this->isMethod('POST'));
    }
}
