<?php

namespace App\Modules\People\Presentation\Http\Requests\Api\V1;

use App\Modules\People\Application\Data\PersonLifecycleData;
use App\Modules\People\Application\Validation\PersonLifecycleInput as Input;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class SavePersonDepartureItemRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('manageItems', [PersonDepartureItem::class, $context]) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return [...Input::itemRules($this->isMethod('POST')), 'version' => $this->isMethod('POST') ? ['prohibited'] : ['required', 'uuid']];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return Input::messages();
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, __('people.validation.immutable'));
            }
        }];
    }

    public function toData(): PersonLifecycleData
    {
        $values = $this->validated();
        $version = $values['version'] ?? null;
        unset($values['version']);

        return new PersonLifecycleData($values, $version);
    }
}
