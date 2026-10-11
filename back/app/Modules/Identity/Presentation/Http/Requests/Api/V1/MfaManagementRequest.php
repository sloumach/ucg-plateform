<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

final class MfaManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('view', $this->user()) ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['password' => ['bail', 'required', 'string', 'max:255', 'current_password:web']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('identity.login.password.required'),
            'password.string' => __('identity.mfa.password_string'),
            'password.max' => __('identity.mfa.password_max'),
            'password.current_password' => __('identity.sessions.password_invalid'),
        ];
    }
}
