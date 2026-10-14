<?php

namespace App\Modules\Contracts\Presentation\Http\Requests\Api\V1;

use App\Modules\Contracts\Application\Validation\ContractGovernanceInput;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContractGovernanceRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        $financial = str_contains((string) $this->route()?->getName(), 'financial');
        $ability = $financial ? ($this->isMethod('GET') ? 'financialRead' : 'financialWrite')
            : ($this->isMethod('GET') ? 'viewAny' : 'approveAmendment');

        return $this->user()?->can($ability, [ContractRecord::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return str_contains((string) $this->route()?->getName(), 'financial') ? [] : ContractInput::pageRules(true);
        }

        return $this->isMethod('PATCH') ? ContractGovernanceInput::financialRules() : ContractGovernanceInput::approvalRules();
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('managed_contracts.unknown'));
            }
        }];
    }

    public function messages(): array
    {
        return ContractInput::messages();
    }
}
