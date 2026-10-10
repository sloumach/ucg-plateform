<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Validation\MembershipInput;

final class InvitationResponseRequest extends TenancyActionRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['decision' => ['required', 'in:accepted,declined'], 'roles' => ['prohibited'], 'organization_id' => ['prohibited'],
            'user_id' => ['prohibited'], 'status' => ['prohibited'], 'confirm' => ['required', 'accepted']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MembershipInput::messages();
    }
}
