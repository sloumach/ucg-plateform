<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Application\Data\PersonData;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SavePersonRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->isMethod('POST') ? 'create' : 'update', [Person::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [...PersonInput::rules($this->isMethod('POST')),
            'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'uuid']];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('people.validation.immutable'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return PersonInput::messages();
    }

    public function toData(): PersonData
    {
        return new PersonData($this->safe()->except(['version']), $this->filled('version') ? $this->string('version')->toString() : null);
    }
}
