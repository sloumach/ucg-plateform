<?php

namespace App\Modules\Teams\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamEligibilityInput
{
    public const int MAX_RULESETS = 1000;

    public const int MAX_REPORTS = 100;

    public const int MAX_AGE = 120;

    /** @return array<string,array<mixed>> */
    public static function readRules(): array
    {
        return [...GameCatalogueInput::pageRules(false), 'game_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', Rule::in(['active', 'archived'])]];
    }

    /** @return array<string,array<mixed>> */
    public static function createRules(): array
    {
        return ['name' => ['required', 'string', 'max:120', 'regex:/\\S/u'], 'game_id' => ['required', 'uuid'],
            'min_starters' => ['required', 'integer', 'between:1,50'], 'max_starters' => ['required', 'integer', 'between:1,50', 'gte:min_starters'],
            'max_substitutes' => ['required', 'integer', 'between:0,49'],
            'platform_key' => ['present', 'nullable', 'string', 'max:32', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'allowed_regions' => ['present', 'array', 'list', 'max:32'], 'allowed_regions.*' => ['required', 'string', 'max:32', 'distinct', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'allowed_ranks' => ['present', 'array', 'list', 'max:32'], 'allowed_ranks.*' => ['required', 'string', 'max:80', 'distinct:ignore_case', 'regex:/\\S/u'],
            'min_age' => ['present', 'nullable', 'integer', 'between:0,'.self::MAX_AGE], 'max_age' => ['present', 'nullable', 'integer', 'between:0,'.self::MAX_AGE],
            'observation_max_days' => ['required', 'integer', 'between:1,3650'], 'lock_at' => ['present', 'nullable', 'date_format:Y-m-d\\TH:i:sP']];
    }

    /** @return array<string,array<mixed>> */
    public static function archiveRules(): array
    {
        return ['version' => ['required', 'uuid'], 'action' => ['required', Rule::in(['archive'])]];
    }

    /** @return array<string,array<mixed>> */
    public static function bindingRules(): array
    {
        return ['version' => ['required', 'uuid'], 'ruleset_id' => ['required', 'uuid'], 'ruleset_version' => ['required', 'uuid']];
    }

    /** @return array<string,array<mixed>> */
    public static function evaluateRules(): array
    {
        return ['version' => ['required', 'uuid']];
    }

    /** @param array<string,mixed> $values
     * @param  array<string,array<mixed>>  $rules
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $values, array $rules): array
    {
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        $unknown = array_diff(array_keys($values), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('team_lineups.server_fields')));
        }

        return $factory->make($values, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [...GameCatalogueInput::messages(), 'date_format' => __('team_memberships.date'), 'gte' => __('team_eligibility.range')];
    }
}
