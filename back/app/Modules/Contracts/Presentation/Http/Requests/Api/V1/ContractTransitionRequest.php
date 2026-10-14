<?php

namespace App\Modules\Contracts\Presentation\Http\Requests\Api\V1;

use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContractTransitionRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        $target = $this->input('status');

        return $this->user()?->can('transition', [ContractRecord::class, $context, is_string($target) ? $target : '']) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ContractInput::transitionRules();
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $roots = array_filter(array_keys($this->rules()), fn (string $name): bool => ! str_contains($name, '.'));
            foreach (array_diff(array_keys($this->all()), $roots) as $field) {
                $validator->errors()->add($field, __('managed_contracts.unknown'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ContractInput::messages();
    }
}
