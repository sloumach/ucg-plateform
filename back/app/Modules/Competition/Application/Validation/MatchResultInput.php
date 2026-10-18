<?php

namespace App\Modules\Competition\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class MatchResultInput
{
    public const MAX_EVENTS = 200;

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        if ($operation === 'index') {
            return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'management' => ['sometimes', 'boolean']];
        }
        $rules = ['version' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u'], 'confirm_sharing' => ['sometimes', 'accepted']];
        if ($operation === 'submit') {
            return [...$rules, 'score_revision' => ['sometimes', 'nullable', 'uuid'], 'outcome' => ['required', 'in:played,forfeit,abandon'], 'proof_reference' => ['required', 'string', 'min:10', 'max:2000', 'regex:/\S/u'],
                'confirm_proof' => ['required', 'accepted'], 'winner_participant_id' => ['nullable', 'uuid']];
        }

        return [...$rules, 'submission_id' => ['required', 'uuid'], ...($operation === 'approve' ? ['confirm_evidence' => ['required', 'accepted']] : [])];
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
        return [...MatchInput::messages(), 'accepted' => __('match_results.confirm'), 'between' => __('match_scores.range')];
    }
}
