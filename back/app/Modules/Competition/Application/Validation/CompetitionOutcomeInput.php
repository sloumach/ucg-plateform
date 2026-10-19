<?php

namespace App\Modules\Competition\Application\Validation;

use Closure;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class CompetitionOutcomeInput
{
    public const MAX_REVISIONS = 1000;

    public const CURRENCIES = ['EUR', 'USD', 'GBP', 'CAD', 'CHF', 'JPY', 'AUD'];

    /** @return array<string,mixed> */
    public static function rules(string $operation, string $stream): array
    {
        if (in_array($operation, ['show', 'result'], true)) {
            return [];
        }
        if ($operation !== 'save') {
            return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,10']];
        }
        $integer = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value)) {
                $fail(__('competition_outcomes.integer'));
            }
        };
        $nullable = ['present', 'nullable', 'string'];
        $common = ['competition_version' => ['required', 'uuid'], 'expected_version' => ['present', 'nullable', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'],
            'source' => ['required', 'string', 'max:500', 'regex:/\S/u'], 'source_url' => array_map(fn ($rule) => $rule === 'sometimes' ? 'present' : $rule, ExternalCompetitionInput::rules('store')['website_url']),
            'observed_on' => ['required', 'date_format:Y-m-d'], 'notes' => [...$nullable, 'max:2000']];
        if ($stream === 'awards') {
            return [...$common, 'currency' => ['required', 'in:'.implode(',', self::CURRENCIES)],
                'announced_minor' => ['present', 'nullable', 'integer', 'between:0,1000000000000', $integer],
                'received_minor' => ['present', 'nullable', 'integer', 'between:0,1000000000000', $integer],
                'received_on' => ['present', 'nullable', 'date_format:Y-m-d']];
        }

        return [...$common, 'place' => ['present', 'nullable', 'integer', 'between:1,1000000', $integer], 'field_size' => ['present', 'nullable', 'integer', 'between:1,1000000', $integer],
            'matches' => ['present', 'array', 'list', 'max:20'], 'matches.*' => ['array:reference_id,match_id,match_version'],
            'matches.*.reference_id' => ['present', 'nullable', 'uuid', 'distinct:ignore_case'],
            'matches.*.match_id' => ['present', 'nullable', 'uuid', 'distinct:ignore_case'], 'matches.*.match_version' => ['present', 'nullable', 'uuid']];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(Factory $factory, array $input, string $operation, string $stream): array
    {
        $rules = self::rules($operation, $stream);
        $unknown = array_diff(array_keys($input), array_filter(array_keys($rules), fn (string $k): bool => ! str_contains($k, '.')));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('external_competitions.closed')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [...ExternalCompetitionInput::messages(), 'date_format' => __('competition_outcomes.date'), 'distinct' => __('competition_outcomes.duplicate'), 'integer' => __('competition_outcomes.integer')];
    }
}
