<?php

namespace App\Modules\Teams\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamLineupInput
{
    public const int MAX_ENTRIES = 50;

    public const int MAX_LINEUPS = 1000;

    /** @return array<string,array<mixed>> */
    public static function readRules(): array
    {
        return [...GameCatalogueInput::pageRules(false), 'purpose' => ['sometimes', Rule::in(['match', 'registration'])], 'status' => ['sometimes', Rule::in(['draft', 'finalized', 'cancelled'])]];
    }

    /** @return array<string,array<mixed>> */
    public static function compositionRules(): array
    {
        return ['team_version' => ['required', 'uuid'], 'label' => ['required', 'string', 'max:120'],
            'effective_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'],
            'entries' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entries.*' => ['required', 'array:membership_id,membership_version,slot_key'],
            'entries.*.membership_id' => ['required', 'uuid', 'distinct:ignore_case'],
            'entries.*.membership_version' => ['required', 'uuid'],
            'entries.*.slot_key' => ['required', Rule::in(['starter', 'substitute'])]];
    }

    /** @return array<string,array<mixed>> */
    public static function createRules(): array
    {
        return [...self::compositionRules(), 'purpose' => ['required', Rule::in(['match', 'registration'])]];
    }

    /** @return array<string,array<mixed>> */
    public static function actionRules(string $action): array
    {
        return ['version' => ['required', 'uuid'], 'action' => ['required', Rule::in(['replace', 'finalize', 'cancel'])],
            ...($action === 'replace' ? self::compositionRules() : ($action === 'finalize' ? ['team_version' => ['required', 'uuid']] : []))];
    }

    /** @param array<string,mixed> $input
     * @param  array<string,array<mixed>>  $rules
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input, array $rules): array
    {
        $allowed = array_filter(array_keys($rules), fn (string $key): bool => ! str_contains($key, '.'));
        $unknown = array_diff(array_keys($input), $allowed);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('team_lineups.server_fields')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [...GameCatalogueInput::messages(), 'date_format' => __('team_memberships.date'),
            'array' => __('team_lineups.entries_invalid'), 'distinct' => __('team_lineups.duplicate'), 'list' => __('team_lineups.entries_invalid')];
    }
}
