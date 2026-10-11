<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class MfaCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->user()) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        if ($this->routeIs('api.v1.mfa.confirm')) {
            return ['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/D'], 'recovery_code' => ['prohibited']];
        }

        return [
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string', 'regex:/\A[0-9]{6}\z/D'],
            'recovery_code' => ['required_without:code', 'prohibits:code', 'string', 'regex:/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{8}){3}\z/D'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required' => __('identity.mfa.code_required'),
            'code.required_without' => __('identity.mfa.code_required'),
            'recovery_code.required_without' => __('identity.mfa.code_required'),
            'code.string' => __('identity.mfa.invalid_code'),
            'recovery_code.string' => __('identity.mfa.invalid_code'),
            'code.regex' => __('identity.mfa.invalid_code'),
            'recovery_code.regex' => __('identity.mfa.invalid_code'),
            'code.prohibits' => __('identity.mfa.one_factor'),
            'recovery_code.prohibits' => __('identity.mfa.one_factor'),
            'recovery_code.prohibited' => __('identity.mfa.authenticator_required'),
        ];
    }
}
