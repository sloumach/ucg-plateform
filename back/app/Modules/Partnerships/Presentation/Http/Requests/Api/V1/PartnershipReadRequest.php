<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Domain\Models\Sponsor;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class PartnershipReadRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('viewAny', [Sponsor::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return PartnershipInput::pageRules((string) ($this->route('kind') ?? 'sponsors'));
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('partnerships.immutable'));
            }
        }];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return PartnershipInput::messages();
    }
}
