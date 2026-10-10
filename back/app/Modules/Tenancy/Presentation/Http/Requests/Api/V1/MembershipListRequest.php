<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Validation\MembershipInput;
use Illuminate\Foundation\Http\FormRequest;

final class MembershipListRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', $context) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'organization_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MembershipInput::messages();
    }
}
