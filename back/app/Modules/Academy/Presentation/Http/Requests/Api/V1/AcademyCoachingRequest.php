<?php

namespace App\Modules\Academy\Presentation\Http\Requests\Api\V1;

use App\Modules\Academy\Application\Validation\AcademyCoachingInput;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AcademyCoachingRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        return $this->user()?->can('access', [AcademyObjective::class, $context, (string) $this->route('coaching_kind'), ! $this->isMethod('GET')]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return AcademyCoachingInput::rules((string) $this->route('coaching_kind'), ! $this->isMethod('GET'), $this->isMethod('POST'));
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            foreach (array_diff(array_keys($this->all()), array_keys($this->rules())) as $field) {
                $validator->errors()->add($field, __('academy.immutable'));
            }
        }];
    }
}
