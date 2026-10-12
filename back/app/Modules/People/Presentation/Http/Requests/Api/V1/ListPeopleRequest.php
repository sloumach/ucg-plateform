<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Application\Data\PersonListQuery;
use App\Modules\People\Application\Validation\PersonListInput;
use App\Modules\People\Domain\Models\Person;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class ListPeopleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('view', [Person::class, $context]) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return PersonListInput::rules();
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return PersonListInput::messages();
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

    public function toQuery(): PersonListQuery
    {
        return new PersonListQuery($this->validated());
    }
}
