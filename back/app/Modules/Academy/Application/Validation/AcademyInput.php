<?php

namespace App\Modules\Academy\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AcademyInput
{
    public const int MAX_WEEKLY_SLOTS = 28;

    /** @return array<string, array<mixed>> */
    public static function rules(string $kind, bool $write, bool $creating = false): array
    {
        if (! $write) {
            return ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
                ...($kind === 'cohort' ? ['q' => ['sometimes', 'string', 'max:80'], 'status' => ['sometimes', Rule::in(['active', 'archived'])]] : []),
                ...($kind === 'enrollment' ? ['cycle_id' => ['sometimes', 'uuid']] : [])];
        }
        $rules = $creating ? [] : ['version' => ['required', 'uuid']];
        if ($kind === 'enrollment') {
            return [...$rules, ...($creating ? [
                'cycle_id' => ['required', 'uuid'], 'person_id' => ['required', 'uuid'], 'role' => ['required', Rule::in(['student', 'mentor'])],
                'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            ] : ['status' => ['required', Rule::in(['withdrawn', 'completed'])]])];
        }
        $rules += ['name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120', 'regex:/\S/u'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])]];
        if ($kind === 'cycle') {
            $rules += ['starts_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
                'ends_on' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
                'timezone' => [$creating ? 'required' : 'sometimes', 'timezone:all'],
                'timetable' => [$creating ? 'present' : 'sometimes', 'array', 'list', 'max:'.self::MAX_WEEKLY_SLOTS],
                'timetable.*' => ['required', 'array:day,starts_at,ends_at'],
                'timetable.*.day' => ['required', 'integer', 'between:1,7'],
                'timetable.*.starts_at' => ['required', 'date_format:H:i'],
                'timetable.*.ends_at' => ['required', 'date_format:H:i']];
        }

        return $rules;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public static function validate(Factory $validator, array $input, string $kind, bool $write, bool $creating = false): array
    {
        $rules = self::rules($kind, $write, $creating);
        $unknown = array_diff(array_keys($input), array_map(fn (string $key): string => explode('.', $key)[0], array_keys($rules)));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('academy.immutable')));
        }

        return $validator->make($input, $rules)->validate();
    }

    /** @param list<array{day: int, starts_at: string, ends_at: string}> $slots */
    public static function assertTimetable(array $slots): void
    {
        usort($slots, fn (array $a, array $b): int => [$a['day'], $a['starts_at']] <=> [$b['day'], $b['starts_at']]);
        $previous = null;
        foreach ($slots as $slot) {
            if ($slot['starts_at'] >= $slot['ends_at'] || ($previous !== null && $previous['day'] === $slot['day'] && $slot['starts_at'] < $previous['ends_at'])) {
                throw ValidationException::withMessages(['timetable' => __('academy.timetable_invalid')]);
            }
            $previous = $slot;
        }
    }
}
