<?php

namespace App\Modules\Reporting\Presentation\Http\Requests\Api\V1;

use App\Modules\Reporting\Application\Services\ReportExportService;
use App\Modules\Reporting\Application\Validation\DashboardInput;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ReportExportRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('reporting.exports', $context) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        if ($this->isMethod('POST')) {
            return ['card' => ['required', Rule::in(ReportExportService::CARDS)], 'confirm' => ['required', 'accepted'],
                'request_key' => ['required', 'uuid'], ...array_diff_key(DashboardInput::rules('show'), ['page' => true])];
        }

        return $this->route('export') === null ? ['page' => ['sometimes', 'integer', 'between:1,10000']] : [];
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
