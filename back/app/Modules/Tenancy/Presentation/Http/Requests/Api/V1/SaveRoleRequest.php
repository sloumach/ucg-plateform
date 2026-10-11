<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\RoleData;
use App\Modules\Tenancy\Application\Validation\RoleInput;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;

final class SaveRoleRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', [OrganizationRole::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...RoleInput::roleRules(), 'version' => $this->isMethod('PUT') ? ['required', 'uuid'] : ['prohibited']];
    }

    public function messages(): array
    {
        return RoleInput::messages();
    }

    public function toData(): RoleData
    {
        return new RoleData($this->string('key')->toString(), $this->string('name')->toString(), array_values($this->validated('permissions')), $this->boolean('active'), $this->filled('version') ? $this->string('version')->toString() : null);
    }
}
