<?php

namespace App\Modules\Scheduling\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class CalendarChangeInput
{
    /** @return array<string,array<string>> */
    public static function rules(): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input): array
    {
        if (array_diff(array_keys($input), array_keys(self::rules())) !== []) {
            throw ValidationException::withMessages(['record' => __('calendar.closed')]);
        }

        return $factory->make($input, self::rules(), self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['integer' => __('calendar.integer'), 'between' => __('calendar.choice')];
    }
}
