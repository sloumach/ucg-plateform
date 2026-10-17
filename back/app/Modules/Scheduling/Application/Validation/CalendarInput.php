<?php

namespace App\Modules\Scheduling\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CalendarInput
{
    public const array KINDS = ['training', 'scrim', 'match', 'meeting', 'academy', 'bootcamp', 'sponsor', 'travel'];

    public const array VISIBILITIES = ['private', 'team', 'organization'];

    /** @return array<string,array<mixed>> */
    public static function rules(string $operation): array
    {
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return match ($operation) {
            'index' => [...$page, 'from_date' => ['required', 'date_format:Y-m-d'], 'to_date' => ['required', 'date_format:Y-m-d'],
                'timezone' => ['sometimes', 'required', 'timezone'], 'kind' => ['sometimes', Rule::in(self::KINDS)], 'team_id' => ['sometimes', 'uuid'],
                'management' => ['sometimes', 'boolean'], 'archived' => ['sometimes', 'boolean']],
            'show' => ['management' => ['sometimes', 'boolean']],
            'people','teams' => $page,
            'store','update' => self::saveRules($operation === 'store'),
            default => [],
        };
    }

    /** @return array<string,array<mixed>> */
    private static function saveRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [...($creating ? [] : ['version' => ['required', 'uuid'], 'archive' => ['sometimes', 'accepted']]),
            'title' => [$required, 'required', 'string', 'max:160', 'regex:/\\S/u'], 'kind' => [$required, 'required', Rule::in(self::KINDS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:4000'], 'location' => ['sometimes', 'nullable', 'string', 'max:200'],
            'url' => ['sometimes', 'nullable', 'string', 'max:2000'], 'team_id' => ['sometimes', 'nullable', 'uuid'],
            'starts_at' => [$required, 'required', 'date_format:Y-m-d\\TH:i:sP'], 'ends_at' => [$required, 'required', 'date_format:Y-m-d\\TH:i:sP'],
            'origin_timezone' => [$required, 'required', 'timezone'], 'visibility' => ['sometimes', 'required', Rule::in(self::VISIBILITIES)],
            'confirm_sharing' => ['sometimes', 'accepted'], 'participant_ids' => ['sometimes', 'array', 'list', 'max:50'],
            'participant_ids.*' => ['required', 'uuid', 'distinct:ignore_case']];
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function validate(Factory $validator, array $values, string $operation): array
    {
        $rules = self::rules($operation);
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        if (($unknown = array_diff(array_keys($values), $allowed)) !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('calendar.closed')));
        }

        return $validator->make($values, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return ['required' => __('calendar.required'), 'string' => __('calendar.string'), 'max' => __('calendar.max'),
            'in' => __('calendar.choice'), 'uuid' => __('calendar.uuid'), 'regex' => __('calendar.format'), 'integer' => __('calendar.integer'),
            'min' => __('calendar.min'), 'boolean' => __('calendar.boolean'), 'accepted' => __('calendar.confirm'),
            'date_format' => __('calendar.date'), 'timezone' => __('calendar.timezone'), 'array' => __('calendar.participants'),
            'list' => __('calendar.participants'), 'distinct' => __('calendar.distinct')];
    }
}
