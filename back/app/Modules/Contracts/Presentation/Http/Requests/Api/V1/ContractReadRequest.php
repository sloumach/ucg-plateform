<?php

namespace App\Modules\Contracts\Presentation\Http\Requests\Api\V1;

use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContractReadRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('viewAny', [ContractRecord::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return ContractInput::pageRules(in_array($this->route()?->getName(), ['api.v1.contracts.people', 'api.v1.contracts.transitions'], true));
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
