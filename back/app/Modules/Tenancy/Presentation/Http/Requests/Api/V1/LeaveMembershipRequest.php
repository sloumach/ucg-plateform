<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Validation\MembershipInput;

final class LeaveMembershipRequest extends TenancyActionRequest
{
    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['confirm' => ['required', 'accepted'], 'organization_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MembershipInput::messages();
    }
}
