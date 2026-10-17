<?php

namespace App\Modules\Scheduling\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CalendarResourceInput
{
    /** @return array<string,array<mixed>> */
    public static function rules(string $op): array
    {
        return match ($op) {
            'index' => ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'archived' => ['sometimes', 'boolean']],
            'store' => ['key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'], 'label' => ['required', 'string', 'max:120', 'regex:/\\S/u'], 'kind' => ['required', Rule::in(['room', 'equipment', 'other'])]],
            'update' => ['version' => ['required', 'uuid'], 'label' => ['sometimes', 'required', 'string', 'max:120', 'regex:/\\S/u'], 'archive' => ['sometimes', 'accepted']],
            default => [],
        };
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input, string $op): array
    {
        if (($unknown = array_diff(array_keys($input), array_keys(self::rules($op)))) !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('calendar.closed')));
        }

        return $factory->make($input, self::rules($op), CalendarInput::messages())->validate();
    }
}
