<?php

namespace App\Modules\Academy\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AcademySessionInput
{
    /** @return array<string, array<mixed>> */
    public static function rules(string $kind, bool $write, bool $creating): array
    {
        if (! $write) {
            $rules = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];

            return $kind === 'activities' ? [...$rules, 'from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d']] : $rules;
        }
        $rules = $creating ? [] : ['version' => ['required', 'uuid']];
        if ($kind === 'attendance') {
            return [...$rules, ...($creating ? ['enrollment_id' => ['required', 'uuid']] : []),
                'status' => ['required', Rule::in(['present', 'absent', 'excused', 'late'])],
                'late_minutes' => ['nullable', 'integer', 'min:1', 'max:240', 'required_if:status,late', 'prohibited_unless:status,late'],
                'change_reason' => [$creating ? 'sometimes' : 'required', 'string', 'min:10', 'max:1000', 'regex:/\S/u']];
        }

        return [...$rules, 'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160', 'regex:/\S/u'],
            'activity_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'activity_version' => ['required_unless:status,cancelled', 'uuid'],
            'responsible_enrollment_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in($creating ? ['planned'] : ['planned', 'completed', 'cancelled'])],
            'exercises' => ['sometimes', 'array', 'list', 'max:20'], 'exercises.*' => ['required', 'array:title,instructions,minutes'],
            'exercises.*.title' => ['required', 'string', 'max:160', 'regex:/\S/u'], 'exercises.*.instructions' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'exercises.*.minutes' => ['required', 'integer', 'min:1', 'max:480'],
            'report' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'change_reason' => [$creating ? 'sometimes' : 'required', 'string', 'min:10', 'max:1000', 'regex:/\S/u']];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function validate(Factory $validator, array $input, string $kind, bool $creating, bool $write = true): array
    {
        $rules = self::rules($kind, $write, $creating);
        $unknown = array_diff(array_keys($input), array_map(fn (string $key): string => explode('.', $key)[0], array_keys($rules)));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('academy.immutable')));
        }

        return $validator->make($input, $rules)->validate();
    }
}
