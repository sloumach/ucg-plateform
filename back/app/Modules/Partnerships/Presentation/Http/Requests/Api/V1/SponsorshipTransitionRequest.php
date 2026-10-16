<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;

final class SponsorshipTransitionRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return $this->input('target_status') === 'archived' ? 'manage' : 'validate';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return Input::transitionRules();
    }
}
