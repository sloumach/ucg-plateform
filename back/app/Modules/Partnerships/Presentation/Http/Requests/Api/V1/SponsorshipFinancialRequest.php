<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;

final class SponsorshipFinancialRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return $this->isMethod('GET') ? 'financialRead' : 'financialWrite';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return $this->isMethod('GET') ? [] : Input::financialRules();
    }
}
