<?php

namespace App\Modules\People\Application\Validation;

use App\Modules\People\Domain\GameProfileStatus;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GameProfileInput
{
    public const int MAX_PROFILES_PER_PERSON = 50;

    public const int MAX_ACCOUNTS_PER_PROFILE = 20;

    /** @return array<string, array<mixed>> */
    public static function profileRules(bool $creating): array
    {
        $identity = $creating ? [
            'game_key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'platform_key' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'region_key' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
        ] : [];

        return [...$identity, 'rank' => ['sometimes', 'nullable', 'string', 'max:80'],
            'game_role' => ['sometimes', 'nullable', 'string', 'max:80'], ...self::observationRules()];
    }

    /** @return array<string, array<mixed>> */
    public static function accountRules(bool $creating): array
    {
        return [...($creating ? ['provider_key' => ['required', 'string', 'max:32', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D']] : []),
            'external_id' => ['sometimes', 'nullable', 'string', 'max:191', 'regex:/^[\x21-\x7E]+$/D'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/\S/u'],
            ...self::observationRules()];
    }

    /** @return array<string, array<mixed>> */
    private static function observationRules(): array
    {
        return ['observed_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\TH:i:sP', 'before_or_equal:now'],
            'status' => ['sometimes', 'string', Rule::in(array_column(GameProfileStatus::cases(), 'value'))]];
    }

    /** @return array<string, array<mixed>> */
    public static function pageRules(): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'between:1,100000']];
    }

    /** @param array<string, mixed> $attributes
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Factory $validator, array $attributes, array $rules, bool $creating, ?string $version): array
    {
        $unknown = array_diff(array_keys($attributes), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('game_profiles.validation.immutable')));
        }
        if (! $creating && $attributes === []) {
            throw ValidationException::withMessages(['profile' => __('game_profiles.validation.empty')]);
        }
        foreach (['rank', 'game_role', 'external_id', 'display_name', 'observed_at'] as $nullable) {
            if (($attributes[$nullable] ?? null) === '') {
                $attributes[$nullable] = null;
            }
        }
        $validated = $validator->make([...$attributes, 'version' => $version],
            [...$rules, 'version' => $creating ? ['prohibited'] : ['required', 'uuid']], self::messages())->validate();
        unset($validated['version']);

        return $validated;
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['required' => __('game_profiles.validation.required'), 'string' => __('game_profiles.validation.string'),
            'max' => __('game_profiles.validation.max'), 'in' => __('game_profiles.validation.choice'),
            'integer' => __('game_profiles.validation.integer'), 'between' => __('game_profiles.validation.between'),
            'regex' => __('game_profiles.validation.format'), 'date_format' => __('game_profiles.validation.date'),
            'before_or_equal' => __('game_profiles.validation.future'), 'uuid' => __('game_profiles.validation.uuid'),
            'prohibited' => __('game_profiles.validation.immutable')];
    }
}
