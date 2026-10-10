<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => [
                'required',
                'confirmed',
                Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.required' => __('identity.password_reset.token.required'),
            'email.required' => __('identity.login.email.required'),
            'email.email' => __('identity.login.email.invalid'),
            'password.required' => __('identity.password_reset.password.required'),
            'password.confirmed' => __('identity.password_reset.password.confirmed'),
        ];
    }

    /** @return array{token: string, email: string, password: string, password_confirmation: string} */
    public function credentials(): array
    {
        return [
            'token' => $this->string('token')->toString(),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'password_confirmation' => $this->string('password_confirmation')->toString(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }
}
