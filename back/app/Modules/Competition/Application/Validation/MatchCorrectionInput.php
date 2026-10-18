<?php

namespace App\Modules\Competition\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class MatchCorrectionInput
{
    public const MAX_CORRECTIONS = 100;

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        if ($operation === 'index') {
            return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,10'], 'management' => ['sometimes', 'boolean']];
        }
        if ($operation === 'retry') {
            return [];
        }

        return ['version' => ['required', 'uuid'], 'score_revision' => ['present', 'nullable', 'uuid'], 'idempotency_key' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'], 'confirm_correction' => ['required', 'accepted'], 'confirm_sharing' => ['sometimes', 'accepted'],
            'games' => ['required', 'array', 'list', 'min:1', 'max:100'], 'games.*' => ['required', 'array:game_id,outcome,results'],
            'games.*.game_id' => ['required', 'uuid', 'distinct:ignore_case'], 'games.*.outcome' => ['required', 'in:played,draw,forfeit,abandon,remake'],
            'games.*.results' => ['required', 'array', 'list', 'min:2', 'max:100'], 'games.*.results.*' => ['required', 'array:participant_id,score'],
            'games.*.results.*.participant_id' => ['required', 'uuid'], 'games.*.results.*.score' => ['present', 'nullable', 'integer', 'between:0,1000000']];
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
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
        return [...MatchScoreInput::messages(), 'accepted' => __('match_corrections.confirm')];
    }
}
