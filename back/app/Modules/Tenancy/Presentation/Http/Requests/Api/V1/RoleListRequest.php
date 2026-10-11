<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Validation\RoleInput;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;

final class RoleListRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->routeIs('api.v1.role-assignments.mine')
            ? ($this->user()?->can('view', $context) ?? false)
            : ($this->user()?->can('viewAny', [OrganizationRole::class, $context]) ?? false);
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'user_id' => ['prohibited'], 'organization_id' => ['prohibited']];
    }

    public function messages(): array
    {
        return RoleInput::messages();
    }
}
