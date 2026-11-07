<?php

namespace App\Modules\Media\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkTaskInput
{
    /** @return array<string, array<mixed>> */
    public static function rules(string $operation): array
    {
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];
        $status = ['sometimes', Rule::in(['todo', 'in_progress', 'done'])];

        return match ($operation) {
            'index' => [...$page, 'status' => $status, 'assignee_id' => ['sometimes', 'uuid'], 'due_before' => ['sometimes', 'date_format:Y-m-d']],
            'people', 'history' => $page,
            'store', 'update' => ['title' => [$operation === 'store' ? 'required' : 'sometimes', 'required', 'string', 'max:160', 'regex:/\\S/u'],
                'description' => ['sometimes', 'nullable', 'string', 'max:4000'], 'assignee_id' => ['sometimes', 'nullable', 'uuid'],
                'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'status' => $status,
                ...($operation === 'update' ? ['version' => ['required', 'uuid']] : [])],
            default => [],
        };
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed> */
    public static function validate(Factory $validator, array $values, string $operation): array
    {
        $rules = self::rules($operation);
        if (($unknown = array_diff(array_keys($values), array_keys($rules))) !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('communication.closed')));
        }

        return $validator->make($values, $rules, CommunicationInput::messages())->validate();
    }
}
