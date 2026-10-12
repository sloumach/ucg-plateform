<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Application\Validation\PersonImportInput;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class PersonImportRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manage', [PersonImport::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        if ($this->routeIs('api.v1.people-imports.store')) {
            return ['file' => ['required', 'file', 'extensions:csv', 'mimetypes:text/plain,text/csv,application/csv,application/vnd.ms-excel',
                'max:'.(int) ceil(config('person_imports.max_bytes', 1048576) / 1024)], 'delimiter' => ['required', Rule::in([',', ';'])]];
        }
        if ($this->routeIs('api.v1.people-imports.preview')) {
            return PersonImportInput::mappingRules();
        }
        if ($this->routeIs('api.v1.people-imports.confirm')) {
            return ['version' => ['required', 'uuid'], 'confirm' => ['required', 'accepted'], 'acknowledge_rejected' => ['sometimes', 'boolean']];
        }

        return ['page' => ['sometimes', 'integer', 'between:1,10000']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [...PersonInput::messages(), 'file' => __('people_import.invalid_csv'),
            'extensions' => __('people_import.invalid_csv'), 'mimetypes' => __('people_import.invalid_csv'),
            'accepted' => __('people_lifecycle.confirm'), 'array' => __('people_import.mapping')];
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($this->rules())));
            foreach (array_diff(array_keys($this->all()), $allowed) as $key) {
                $validator->errors()->add($key, __('people.validation.immutable'));
            }
        }];
    }
}
