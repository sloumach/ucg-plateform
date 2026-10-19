<?php

namespace App\Modules\Performance\Presentation\Http\Requests\Api\V1;

use App\Modules\Performance\Application\Validation\StatisticalProfileInput;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class StatisticalProfileRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('viewAny', [StatisticalObservation::class, $context]) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return StatisticalProfileInput::rules($this->route()?->getActionMethod() ?? 'show');
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
