<?php

namespace App\Modules\Academy\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AcademyCoachingInput
{
    /** @return array<string, array<mixed>> */
    public static function rules(string $kind, bool $write, bool $creating): array
    {
        if (! $write) {
            return ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        }
        $rules = $creating ? [] : ['version' => ['required', 'uuid'], 'change_reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u']];
        if ($kind === 'objective') {
            return [...$rules, ...($creating ? ['target_enrollment_id' => ['present', 'nullable', 'uuid']] : []),
                'responsible_enrollment_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
                'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160', 'regex:/\S/u'],
                'description' => ['sometimes', 'nullable', 'string', 'max:4000'],
                'due_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
                'status' => ['sometimes', Rule::in($creating ? ['active'] : ['active', 'completed', 'cancelled'])]];
        }

        return [...$rules, 'content' => [$creating ? 'required' : 'sometimes', 'string', 'max:4000', 'regex:/\S/u'],
            ...($kind === 'internal' ? ['justification' => [$creating ? 'required' : 'sometimes', 'string', 'min:10', 'max:1000', 'regex:/\S/u']] : []),
            'status' => ['sometimes', Rule::in($creating ? ['active'] : ['active', 'withdrawn'])]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function validate(Factory $validator, array $input, string $kind, bool $write, bool $creating): array
    {
        $rules = self::rules($kind, $write, $creating);
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('academy.immutable')));
        }

        return $validator->make($input, $rules)->validate();
    }
}
