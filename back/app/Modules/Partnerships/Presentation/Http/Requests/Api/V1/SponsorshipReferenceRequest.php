<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;

final class SponsorshipReferenceRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return 'viewAny';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return Input::pageRules('references');
    }
}
