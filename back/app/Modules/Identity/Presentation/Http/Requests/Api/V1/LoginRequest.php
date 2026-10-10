<?php

namespace App\Modules\Identity\Presentation\Http\Requests\Api\V1;

use App\Modules\Identity\Application\Data\LoginData;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('identity.login.email.required'),
            'email.email' => __('identity.login.email.invalid'),
            'password.required' => __('identity.login.password.required'),
        ];
    }

    public function toData(): LoginData
    {
        return new LoginData(
            email: $this->string('email')->toString(),
            password: $this->string('password')->toString(),
        );
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => Str::lower(trim((string) $this->input('email'))),
        ]);
    }
}
