<?php

namespace App\Modules\Tenancy\Presentation\Http\Requests\Api\V1;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ActivateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:20000'],
            'password' => ['required', 'string', 'confirmed', 'max:72', 'not_regex:/\x00/', Password::min(12)->letters()->mixedCase()->numbers()->symbols(),
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && strlen($value) > 72) {
                        $fail(__('platform.client_password_length'));
                    }
                }],
        ];
    }
}
