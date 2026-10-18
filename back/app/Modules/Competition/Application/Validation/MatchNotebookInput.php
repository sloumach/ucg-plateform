<?php

namespace App\Modules\Competition\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class MatchNotebookInput
{
    public const MAX_ENTRIES = 500;

    public const MAX_REVISIONS = 100;

    /** @return array<string,mixed> */
    public static function rules(string $operation): array
    {
        $pagination = ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'], 'management' => ['sometimes', 'boolean']];
        if ($operation === 'history') {
            return $pagination;
        }
        if ($operation === 'index') {
            return [...$pagination, 'audience' => ['sometimes', 'in:internal,strategy,shared'], 'kind' => ['sometimes', 'in:objective,note,vod,debrief,action'], 'state' => ['sometimes', 'in:open,done']];
        }

        return [...($operation === 'store' ? ['audience' => ['required', 'in:internal,strategy,shared'], 'kind' => ['required', 'in:objective,note,vod,debrief,action']] : ['version' => ['required', 'uuid']]),
            'title' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:200', 'regex:/\S/u'],
            'body' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:10000', 'regex:/\S/u'],
            'vod_url' => ['sometimes', 'nullable', 'string', 'max:2000', 'url:https'],
            'timestamp_seconds' => ['sometimes', 'nullable', 'integer', 'between:0,604800'],
            'state' => ['sometimes', 'in:open,done'], 'due_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP'],
            'confirm_sharing' => ['sometimes', 'accepted']];
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
        return [...MatchInput::messages(), 'url' => __('match_notebook.url'), 'date_format' => __('match_notebook.date'), 'accepted' => __('match_notebook.sharing')];
    }
}
