<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\SponsorshipAgreementInput as Input;

final class SponsorshipReadRequest extends SponsorshipRequest
{
    protected function ability(): string
    {
        return 'viewAny';
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return Input::pageRules(str_ends_with((string) $this->route()?->getName(), '.history') ? 'history' : 'agreements');
    }
}
