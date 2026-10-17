<?php

namespace App\Modules\Competition\Application\Validation;

use App\Modules\Competition\Domain\MatchWorkflow;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class MatchInput
{
    public const array FORMATS = ['bo1', 'bo3', 'bo5', 'lobby', 'free_for_all'];

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        $page = ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100']];
        if (in_array($operation, ['history', 'references'], true)) {
            return [...$page, ...($operation === 'references' ? ['game_id' => ['sometimes', 'uuid']] : ['management' => ['sometimes', 'boolean']])];
        }
        if (in_array($operation, ['index', 'show'], true)) {
            return [...($operation === 'index' ? [...$page, 'game_id' => ['sometimes', 'uuid'], 'kind' => ['sometimes', Rule::in(['scrim', 'official'])],
                'status' => ['sometimes', Rule::in(MatchWorkflow::STATUSES)]] : []), 'management' => ['sometimes', 'boolean']];
        }
        if ($operation === 'transition') {
            return ['version' => ['required', 'uuid'], 'status' => ['required', Rule::in(MatchWorkflow::STATUSES)],
                'reason' => ['required', 'string', 'min:10', 'max:1000', 'regex:/\S/u']];
        }
        $required = $operation === 'store' ? 'required' : 'sometimes';

        return [...($operation === 'store' ? ['game_id' => ['required', 'uuid']] : ['version' => ['required', 'uuid']]),
            'title' => [$required, 'required', 'string', 'max:160', 'regex:/\S/u'],
            'kind' => [$required, Rule::in(['scrim', 'official'])], 'format' => [$required, Rule::in(self::FORMATS)],
            'starts_at' => [$required, 'string', 'date_format:Y-m-d\TH:i:sP'], 'ends_at' => [$required, 'string', 'date_format:Y-m-d\TH:i:sP'],
            'origin_timezone' => [$required, 'string', 'timezone'], 'responsible_person_id' => [$required, 'uuid'],
            'participants' => [$required, 'required', 'array', 'list', 'min:2', 'max:100'],
            'participants.*' => ['required', 'array:kind,source_id'], 'participants.*.kind' => ['required', Rule::in(['team', 'person', 'opponent_snapshot'])],
            'participants.*.source_id' => ['required', 'uuid'],
            'visibility' => ['sometimes', Rule::in(['private', 'team', 'organization'])], 'confirm_sharing' => ['sometimes', 'accepted']];
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
        return ['required' => __('matches.required'), 'uuid' => __('matches.choice'), 'in' => __('matches.choice'),
            'boolean' => __('matches.choice'), 'integer' => __('matches.choice'), 'between' => __('matches.choice'),
            'string' => __('matches.text'), 'max' => __('matches.bound'), 'min' => __('matches.bound'), 'regex' => __('matches.text'),
            'array' => __('matches.closed'), 'list' => __('matches.closed'), 'date_format' => __('matches.period'), 'timezone' => __('matches.period')];
    }
}
