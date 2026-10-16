<?php

namespace App\Modules\Media\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CommunicationInput
{
    /** @return array<string, array<mixed>> */
    public static function rules(string $operation): array
    {
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']];

        return match ($operation) {
            'index' => [...$page, 'management' => ['sometimes', 'boolean'], 'status' => ['sometimes', Rule::in(['draft', 'published', 'withdrawn'])]],
            'show' => ['management' => ['sometimes', 'boolean']],
            'comments' => [...$page, 'management' => ['sometimes', 'boolean']],
            'targets' => [...$page, 'type' => ['required', Rule::in(['team', 'game', 'role'])]],
            'inbox' => [...$page, 'unread' => ['sometimes', 'boolean']],
            'store' => self::draftRules(),
            'update' => [...self::draftRules(false), 'version' => ['required', 'uuid'], 'action' => ['sometimes', Rule::in(['publish', 'withdraw', 'comments'])],
                'confirm_publication' => ['required_if:action,publish', 'accepted_if:action,publish'], 'notify_members' => ['sometimes', 'boolean']],
            'comment' => ['version' => ['required', 'uuid'], 'body' => ['required', 'string', 'max:4000', 'regex:/\\S/u'], 'notify_members' => ['sometimes', 'boolean']],
            'savePreferences' => ['version' => ['present', 'nullable', 'uuid'], 'announcements_enabled' => ['required', 'boolean'], 'comments_enabled' => ['required', 'boolean']],
            default => [],
        };
    }

    /** @return array<string, array<mixed>> */
    private static function draftRules(bool $creating = true): array
    {
        $r = $creating ? 'required' : 'sometimes';

        return ['title' => [$r, 'required', 'string', 'max:160', 'regex:/\\S/u'], 'body' => [$r, 'required', 'string', 'max:12000', 'regex:/\\S/u'],
            'target_type' => [$r, 'required', Rule::in(['organization', 'team', 'game', 'role'])],
            'target_id' => ['sometimes', 'nullable', 'uuid'], 'comments_enabled' => ['sometimes', 'boolean'],
            'expires_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP', 'after:now']];
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public static function validate(Factory $validator, array $values, string $operation): array
    {
        $rules = self::rules($operation);
        if (($unknown = array_diff(array_keys($values), array_keys($rules))) !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('communication.closed')));
        }

        return $validator->make($values, $rules, self::messages())->validate();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [...MediaInput::messages(), 'accepted_if' => __('communication.confirm'), 'after' => __('communication.future'), 'required_if' => __('communication.confirm'), 'present' => __('communication.version_required')];
    }
}
