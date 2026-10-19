<?php

namespace App\Modules\Performance\Presentation\Http\Requests\Api\V1;

use App\Modules\Performance\Application\Services\StatisticalImportConfirmation;
use App\Modules\Performance\Application\Validation\ObservationInput;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StatisticalImportRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', [StatisticalImport::class, $context]) ?? false;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        if ($this->routeIs('api.v1.statistical-imports.confirm', 'api.v1.statistical-imports.resume')) {
            return StatisticalImportConfirmation::rules($this->routeIs('api.v1.statistical-imports.resume'));
        }

        return $this->isMethod('POST') ? [
            'file' => ['required', 'file', 'extensions:csv', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel', 'max:1024'],
            'delimiter' => ['required', Rule::in([',', ';'])],
        ] : ['page' => ['sometimes', 'integer', 'between:1,100000']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [...ObservationInput::messages(), 'file' => __('statistical_imports.csv'), 'extensions' => __('statistical_imports.csv'), 'mimetypes' => __('statistical_imports.csv'), 'max' => __('statistical_imports.csv')];
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('observations.closed'));
            }
        }];
    }
}
