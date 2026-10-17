<?php

namespace App\Modules\Scheduling\Presentation\Http\Requests\Api\V1;

use App\Modules\Scheduling\Application\Validation\CalendarInput;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CalendarRequest extends FormRequest
{
    public function operation(): string
    {
        return $this->route()?->getActionMethod() ?? '';
    }

    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can(in_array($this->operation(), ['index', 'show'], true) ? 'viewAny' : 'manage', [CalendarActivity::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return CalendarInput::rules($this->operation());
    }

    /** @return list<callable(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn (string $key): bool => ! str_contains($key, '.'));
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
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
