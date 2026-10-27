<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Application\Data\PersonArchiveData;
use App\Modules\People\Application\Validation\PersonInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ChangePersonArchiveRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('archive', [Person::class, $context]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return PersonInput::archiveRules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return PersonInput::messages();
    }

    /** @return list<\Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, __('people.validation.immutable'));
            }
        }];
    }

    public function toData(): PersonArchiveData
    {
        return new PersonArchiveData($this->validated('version'), $this->boolean('confirm'));
    }
}
