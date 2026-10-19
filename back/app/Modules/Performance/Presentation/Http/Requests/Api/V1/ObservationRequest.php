<?php

namespace App\Modules\Performance\Presentation\Http\Requests\Api\V1;

use App\Modules\Performance\Application\Validation\ObservationInput as Input;
use App\Modules\Performance\Domain\Models\StatisticalObservation;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ObservationRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(match ($this->route()?->getActionMethod()) {
            'store','choices' => 'create','update' => 'correct',default => 'viewAny'
        }, [StatisticalObservation::class, $context]) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return Input::rules($this->route()?->getActionMethod() ?? 'index');
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return Input::messages();
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $v): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $v->errors()->add($key, __('observations.closed'));
            }
        }];
    }
}
