<?php

namespace App\Modules\Reporting\Presentation\Http\Requests\Api\V1;

use App\Modules\Reporting\Application\Validation\TenantSearchInput;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class TenantSearchRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('search.viewAny', $context) ?? false;
    }

    /** @return array<string,array<mixed>> */
    public function rules(): array
    {
        return $this->routeIs('api.v1.search.types') ? [] : TenantSearchInput::rules();
    }

    /** @return list<\Closure(Validator):void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $key) {
                $validator->errors()->add($key, __('search.closed'));
            }
        }];
    }
}
