<?php

namespace App\Modules\Competition\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class MatchLineupInput
{
    public const MAX_REVISIONS = 500;

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        if (in_array($operation, ['index', 'choices'], true)) {
            return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
                ...($operation === 'index' ? ['management' => ['sometimes', 'boolean']] : [])];
        }

        return ['version' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'],
            ...($operation === 'store' ? ['lineup_id' => ['required', 'uuid'], 'confirm_sharing' => ['sometimes', 'accepted']] : ['out_person_id' => ['required', 'uuid'], 'in_person_id' => ['required', 'uuid', 'different:out_person_id']])];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input, string $operation): array
    {
        if (array_diff(array_keys($input), array_keys(self::rules($operation))) !== []) {
            throw ValidationException::withMessages(['record' => __('matches.closed')]);
        }

        return $factory->make($input, self::rules($operation), self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [...MatchInput::messages(), 'different' => __('match_lineups.substitution'), 'accepted' => __('matches.sharing')];
    }
}
