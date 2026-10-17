<?php

namespace App\Modules\Scheduling\Presentation\Http\Requests\Api\V1;

use App\Modules\Scheduling\Application\Validation\CalendarInput;
use App\Modules\Scheduling\Application\Validation\CalendarResourceInput;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CalendarResourceRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->route()?->getActionMethod() === 'index' ? 'manage' : 'manageResources', [CalendarActivity::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return CalendarResourceInput::rules($this->route()?->getActionMethod() ?? '');
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
        return CalendarInput::messages();
    }
}
