<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email:rfc', 'max:255']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('identity.login.email.required'),
            'email.email' => __('identity.login.email.invalid'),
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }
}
