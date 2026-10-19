<?php

namespace App\Modules\Reporting\Presentation\Http\Requests\Api\V1;

use App\Modules\Reporting\Application\Validation\DashboardInput;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class DashboardRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('reporting.viewAny', $context) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return DashboardInput::rules($this->route()?->getActionMethod() ?? 'index');
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, __('dashboards.closed'));
            }
        }];
    }
}
