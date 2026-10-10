<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

final class RevokeOtherSessionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:web', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('identity.login.password.required'),
            'password.current_password' => __('identity.sessions.password_invalid'),
        ];
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}
