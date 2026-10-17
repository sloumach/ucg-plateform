<?php

namespace App\Modules\Scheduling\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class CalendarFeedInput
{
    /** @return array<string,array<mixed>> */
    public static function rules(string $operation): array
    {
        return match ($operation) {
            'store' => ['expected_version' => ['present', 'nullable', 'uuid'], 'before_days' => ['required', 'integer:strict', 'between:0,30'],
                'after_days' => ['required', 'integer:strict', 'between:1,90'], 'expires_in_days' => ['required', 'integer:strict', 'between:1,90'],
                'confirm_export' => ['required', 'accepted']],
            'revoke' => ['version' => ['required', 'uuid'], 'confirm_revocation' => ['required', 'accepted']],
            default => [],
        };
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        if (array_diff(array_keys($input), array_keys(self::rules($operation))) !== []) {
            throw ValidationException::withMessages(['record' => __('calendar.closed')]);
        }
        $values = $factory->make($input, self::rules($operation), self::messages())->validate();
        if ($operation === 'store' && $values['before_days'] + $values['after_days'] > 93) {
            throw ValidationException::withMessages(['after_days' => __('calendar.feed_window')]);
        }

        return $values;
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['required' => __('calendar.required'), 'present' => __('calendar.required'), 'uuid' => __('calendar.uuid'),
            'accepted' => __('calendar.feed_confirm'), 'integer' => __('calendar.integer'), 'between' => __('calendar.feed_bounds')];
    }
}
