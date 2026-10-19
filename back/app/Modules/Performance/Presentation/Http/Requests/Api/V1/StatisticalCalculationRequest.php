<?php

namespace App\Modules\Performance\Presentation\Http\Requests\Api\V1;

use App\Modules\Performance\Application\Validation\StatisticalCalculationInput;
use App\Modules\Performance\Domain\Models\StatisticalCalculation;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StatisticalCalculationRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(in_array($this->route()?->getActionMethod(), ['store', 'recalculate'], true) ? 'create' : 'viewAny',
            [StatisticalCalculation::class, $context]) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return StatisticalCalculationInput::rules($this->route()?->getActionMethod() ?? 'index');
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, __('observations.closed'));
            }
        }];
    }
}
