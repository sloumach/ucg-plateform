<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Validation\MembershipInput;

final class ConfirmOrganizationRequest extends TenancyActionRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['organization_id' => ['required', 'uuid'], 'revision' => ['required', 'uuid'], 'confirm' => ['required', 'accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MembershipInput::messages();
    }
}
