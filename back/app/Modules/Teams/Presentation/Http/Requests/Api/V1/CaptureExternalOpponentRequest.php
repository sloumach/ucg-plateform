<?php

namespace App\Modules\Teams\Presentation\Http\Requests\Api\V1;

use App\Modules\Teams\Application\Validation\ExternalOpponentInput;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CaptureExternalOpponentRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', [ExternalOpponent::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ExternalOpponentInput::captureRules();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('external_opponents.immutable'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ExternalOpponentInput::messages();
    }
}
