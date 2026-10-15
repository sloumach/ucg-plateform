<?php

namespace App\Modules\Contracts\Presentation\Http\Requests\Api\V1;

use App\Modules\Contracts\Application\Services\ContractReminderService;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\ContractReminderSchedule;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ContractReminderRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->isMethod('GET') ? 'viewAny' : 'manage', [ContractRecord::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ContractInput::pageRules(true);
        }

        return $this->isMethod('PATCH') ? ContractReminderSchedule::rules() : ContractReminderService::resolutionRules();
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $roots = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $roots) as $field) {
                $validator->errors()->add($field, __('managed_contracts.unknown'));
            }
        }];
    }

    public function messages(): array
    {
        return [...ContractInput::messages(), ...ContractReminderSchedule::messages()];
    }
}
