<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;

final class RoleAssignmentMemberListRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('assign', [OrganizationRole::class, $context]) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:254'], 'page' => ['sometimes', 'integer', 'between:1,100000'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'], 'organization_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }
}
