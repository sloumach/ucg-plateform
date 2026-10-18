<?php

namespace App\Modules\Competition\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class MatchScoreInput
{
    public const MAX_GAMES = 100;

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        if ($operation === 'index') {
            return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'management' => ['sometimes', 'boolean']];
        }
        $common = ['version' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'], 'confirm_sharing' => ['sometimes', 'accepted']];
        if ($operation === 'configure') {
            return [...$common, 'allow_draw' => ['required', 'boolean'], 'game_limit' => ['required', 'integer', 'between:1,100']];
        }

        return [...$common, 'outcome' => ['required', 'in:played,draw,forfeit,abandon,remake'],
            'map' => ['nullable', 'string', 'max:160', 'required_without:mode', 'regex:/\S/u'],
            'mode' => ['nullable', 'string', 'max:160', 'required_without:map', 'regex:/\S/u'],
            'game_patch' => ['required', 'string', 'max:80', 'regex:/\S/u'],
            'results' => ['required', 'array', 'list', 'min:2', 'max:100'],
            'results.*' => ['required', 'array:participant_id,score'],
            'results.*.participant_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'results.*.score' => ['present', 'nullable', 'integer', 'between:0,1000000']];
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
        return [...MatchInput::messages(), 'between' => __('match_scores.range'), 'distinct' => __('match_scores.participants'),
            'required_without' => __('match_scores.map_mode'), 'present' => __('match_scores.score'), 'accepted' => __('matches.sharing')];
    }
}
