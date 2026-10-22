<?php

namespace App\Modules\Academy\Presentation\Http\Requests\Api\V1;

use App\Modules\Academy\Application\Validation\AcademyInput;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class AcademyRequest extends FormRequest
{
    public function authorize(TenantContext $context): bool
    {
        $ability = $this->isMethod('GET') && $this->route('academy_kind') !== 'choices' ? 'viewAny' : 'manage';

        return $this->user()?->can($ability, [Cohort::class, $context]) ?? false;
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return AcademyInput::rules((string) $this->route('academy_kind'), ! $this->isMethod('GET'), $this->isMethod('POST'));
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_map(fn (string $key): string => explode('.', $key)[0], array_keys($this->rules()));
            foreach (array_diff(array_keys($this->all()), $allowed) as $field) {
                $validator->errors()->add($field, __('academy.immutable'));
            }
        }];
    }
}
