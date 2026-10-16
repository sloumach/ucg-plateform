<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;

final class SaveSponsorshipAgreementRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return 'manage';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return Input::saveRules($this->isMethod('POST'));
    }
}
