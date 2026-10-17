<?php

namespace App\Modules\Scheduling\Presentation\Http\Requests\Api\V1;

use App\Modules\Scheduling\Application\Validation\AvailabilityInput;
use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AvailabilityRequest extends FormRequest
{
    private function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
    }

    public function authorize(TenantContext $context): bool
    {
        $ability = match ($this->operation()) {
            'store','update' => 'manage','people' => 'people',default => 'viewAny'
        };

        return $this->user()?->can($ability, [Availability::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return AvailabilityInput::rules($this->operation());
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('calendar.closed'));
            }
        }];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return AvailabilityInput::messages();
    }
}
