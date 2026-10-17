<?php

namespace App\Modules\Scheduling\Presentation\Http\Requests\Api\V1;

use App\Modules\Scheduling\Application\Validation\CalendarChangeInput;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CalendarChangeRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->route()?->getActionMethod() === 'history' ? 'manage' : 'viewAny', [CalendarActivity::class, $context]) ?? false;
    }

    /** @return array<string,array<string>> */
    public function rules(): array
    {
        return CalendarChangeInput::rules();
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return CalendarChangeInput::messages();
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
}
