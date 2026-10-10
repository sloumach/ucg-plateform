<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\InvitationData;
use App\Modules\Tenancy\Application\Validation\MembershipInput;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class InviteOrganizationRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', $context) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
        if (! $this->has('starts_at')) {
            $this->merge(['starts_at' => now()->toIso8601String()]);
        }
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...MembershipInput::periodRules(), 'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'id' => ['prohibited'], 'organization_id' => ['prohibited'], 'status' => ['prohibited'], 'invited_by' => ['prohibited'],
            'responded_by' => ['prohibited'], 'expires_at' => ['prohibited'], 'user_id' => ['prohibited']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return MembershipInput::messages();
    }

    public function toData(): InvitationData
    {
        return new InvitationData($this->string('email')->toString(),
            array_values(array_map(fn (string $role): string => $role, $this->array('roles'))),
            $this->string('starts_at')->toString(), $this->filled('ends_at') ? $this->string('ends_at')->toString() : null);
    }
}
