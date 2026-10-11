<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\RoleAssignmentData;
use App\Modules\Tenancy\Application\Validation\RoleInput;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use Illuminate\Foundation\Http\FormRequest;

final class SaveRoleAssignmentRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('assign', [OrganizationRole::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...RoleInput::assignmentRules(), 'version' => $this->isMethod('PUT') ? ['required', 'uuid'] : ['prohibited']];
    }

    public function messages(): array
    {
        return RoleInput::messages();
    }

    public function toData(): RoleAssignmentData
    {
        return new RoleAssignmentData($this->string('role_id')->toString(), $this->integer('user_id'), $this->string('scope_type')->toString(),
            $this->string('scope_id')->toString(), $this->string('status')->toString(), $this->string('starts_at')->toString(),
            $this->filled('ends_at') ? $this->string('ends_at')->toString() : null, $this->filled('version') ? $this->string('version')->toString() : null);
    }
}
