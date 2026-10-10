<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\MembershipData;
use App\Modules\Tenancy\Application\Validation\MembershipInput;
use App\Modules\Tenancy\Domain\MembershipStatus;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateMembershipRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', $context) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...MembershipInput::periodRules(), 'status' => ['required', 'in:active,suspended,revoked'],
            'id' => ['prohibited'], 'organization_id' => ['prohibited'], 'user_id' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MembershipInput::messages();
    }

    public function toData(): MembershipData
    {
        return new MembershipData(MembershipStatus::from($this->string('status')->toString()),
            array_values(array_map(fn (string $role): string => $role, $this->array('roles'))),
            $this->string('starts_at')->toString(), $this->filled('ends_at') ? $this->string('ends_at')->toString() : null);
    }
}
