<?php

namespace App\Modules\Partnerships\Presentation\Http\Requests\Api\V1;

use App\Modules\Partnerships\Application\Validation\PartnershipInput;
use App\Modules\Partnerships\Domain\Models\SponsorshipAgreement;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class SponsorshipRequest extends FormRequest
{
    abstract protected function ability(): string;

    /** @return array<string, array<mixed>> */
    abstract public function rules(): array;

    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can($this->ability(), [SponsorshipAgreement::class, $context]) ?? false;
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
        return [...PartnershipInput::messages(), 'accepted' => __('sponsorships.accepted'), 'required_if' => __('sponsorships.accepted'),
            'array' => __('partnerships.format'), 'present' => __('partnerships.required')];
    }
}
