<?php

namespace App\Modules\Scheduling\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AvailabilityInput
{
    public const array STATUSES = ['available', 'unavailable', 'uncertain'];

    /** @return array<string,array<mixed>> */
    public static function rules(string $operation): array
    {
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        $required = $operation === 'store' ? 'required' : 'sometimes';

        return match ($operation) {
            'index' => [...$page, 'from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d'],
                'timezone' => ['sometimes', 'required', 'timezone'], 'collective' => ['sometimes', 'boolean'],
                'person_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', Rule::in(self::STATUSES)], 'cancelled' => ['sometimes', 'boolean']],
            'people','history' => $page,
            'show' => [],
            'store','update' => [...($operation === 'store' ? ['person_id' => ['sometimes', 'uuid']] : ['version' => ['required', 'uuid'], 'cancel' => ['sometimes', 'accepted']]),
                'status' => [$required, 'required', Rule::in(self::STATUSES)], 'comment' => ['sometimes', 'nullable', 'string', 'max:1000'],
                'starts_at' => [$required, 'required', 'date_format:Y-m-d\\TH:i:sP'], 'ends_at' => [$required, 'required', 'date_format:Y-m-d\\TH:i:sP'],
                'origin_timezone' => [$required, 'required', 'timezone']],
            default => [],
        };
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(Factory $validator, array $input, string $operation): array
    {
        if (($unknown = array_diff(array_keys($input), array_keys(self::rules($operation)))) !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('calendar.closed')));
        }

        return $validator->make($input, self::rules($operation), self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return CalendarInput::messages();
    }
}
