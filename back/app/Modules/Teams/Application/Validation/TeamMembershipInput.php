<?php

namespace App\Modules\Teams\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class TeamMembershipInput
{
    public const array ROLES = ['player', 'substitute', 'captain', 'coach', 'analyst'];

    public const array REASONS = ['initial', 'sporting_decision', 'promotion', 'transfer', 'end_of_period', 'person_departure', 'other'];

    public const int MAX_MEMBERSHIPS = 1000;

    public const int MAX_SCOPE_GRANTS = 1000;

    /** @param array<string,mixed> $input
     * @param  array<string,array<mixed>>  $rules
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, array $input, array $rules): array
    {
        $unknown = array_diff(array_keys($input), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('team_memberships.immutable')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string,array<mixed>> */
    public static function readRules(): array
    {
        return [...GameCatalogueInput::pageRules(false), 'role_key' => ['sometimes', Rule::in(self::ROLES)],
            'person_id' => ['sometimes', 'uuid'], 'status' => ['sometimes', Rule::in(['active', 'closed', 'cancelled'])],
            'at' => ['sometimes', 'date_format:Y-m-d\\TH:i:sP']];
    }

    /** @return array<string,array<mixed>> */
    public static function createRules(): array
    {
        return ['person_id' => ['required', 'uuid'], 'role_key' => ['required', Rule::in(self::ROLES)],
            'starts_at' => ['required', 'date_format:Y-m-d\\TH:i:sP'], 'ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP'],
            'reason_key' => ['required', Rule::in(['initial', 'sporting_decision', 'other'])]];
    }

    /** @return array<string,array<mixed>> */
    public static function actionRules(): array
    {
        return ['version' => ['required', 'uuid'], 'action' => ['required', Rule::in(['close', 'cancel', 'transfer', 'promotion'])],
            'effective_at' => ['required_if:action,close', 'date_format:Y-m-d\\TH:i:sP'],
            'reason_key' => ['required', Rule::in(['sporting_decision', 'promotion', 'transfer', 'end_of_period', 'other'])],
            'destination_team_id' => ['required_if:action,transfer,promotion', 'uuid'],
            'destination_version' => ['required_if:action,transfer,promotion', 'uuid'],
            'role_key' => ['required_if:action,transfer,promotion', Rule::in(self::ROLES)]];
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [...GameCatalogueInput::messages(), 'date_format' => __('team_memberships.date'), 'required_if' => GameCatalogueInput::messages()['required']];
    }
}
