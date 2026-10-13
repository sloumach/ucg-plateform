<?php

namespace App\Modules\Teams\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class GameCatalogueInput
{
    public const array KINDS = ['game', 'platform', 'provider', 'region', 'mode', 'role', 'map', 'release'];

    public const array SCOPED = ['mode', 'role', 'map', 'release'];

    public const array GLOBAL = ['game', 'platform', 'provider', 'region'];

    public const array RESTRICTIONS = ['platform', 'provider', 'region', 'mode', 'role', 'map', 'release'];

    public const int MAX_GAMES = 100;

    public const int MAX_GLOBAL_REFERENCES = 32;

    public const int MAX_GAME_REFERENCES = 200;

    /** @return array<string, array<mixed>> */
    public static function pageRules(bool $global): array
    {
        return ['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'between:1,100000'],
            ...($global ? ['kind' => ['sometimes', 'string', Rule::in(self::KINDS)], 'game_id' => ['sometimes', 'nullable', 'uuid']] : [])];
    }

    /** @return array<string, array<mixed>> */
    public static function entryRules(bool $creating): array
    {
        return [...($creating ? ['kind' => ['required', 'string', Rule::in(self::KINDS)],
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'game_id' => ['sometimes', 'nullable', 'uuid']] : ['version' => ['required', 'uuid']]),
            'name' => ['required', 'string', 'max:120', 'regex:/\\S/u'], 'active' => ['required', 'boolean'],
            'definition' => ['sometimes', 'nullable', 'array:platforms,regions'],
            'definition.platforms' => ['sometimes', 'array', 'max:32'],
            'definition.platforms.*' => ['array', 'max:32'],
            'definition.platforms.*.*' => ['string', 'max:32', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'definition.regions' => ['sometimes', 'array', 'list', 'max:32'],
            'definition.regions.*' => ['string', 'max:32', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'distinct:strict'],
            'catalogue_version' => ['required', 'uuid'], 'confirm_global' => ['required', 'accepted']];
    }

    /** @return array<string, array<mixed>> */
    public static function settingRules(): array
    {
        $rules = ['enabled' => ['required', 'boolean'], 'version' => ['present', 'nullable', 'uuid'],
            'catalogue_version' => ['required', 'uuid'], 'restrictions' => ['present', 'array:'.implode(',', self::RESTRICTIONS)]];
        foreach (self::RESTRICTIONS as $kind) {
            $rules['restrictions.'.$kind] = ['sometimes', 'array', 'list', 'max:200'];
            $rules['restrictions.'.$kind.'.*'] = ['string', 'max:64', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', 'distinct:strict'];
        }

        return $rules;
    }

    /** @param array<string, mixed> $input
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, mixed>
     */
    public static function validate(Factory $factory, array $input, array $rules): array
    {
        $roots = array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.'));
        $unknown = array_diff(array_keys($input), $roots);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('game_catalogue.immutable')));
        }

        return $factory->make($input, $rules, self::messages())->validate();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'present' => 'Ce champ doit être fourni.',
            'string' => 'Ce champ doit être un texte.', 'boolean' => 'Choisissez oui ou non.',
            'array' => 'La liste fournie est invalide.', 'list' => 'Une liste ordonnée est attendue.',
            'max' => 'Ce champ dépasse la limite autorisée.', 'between' => 'Ce champ est hors des limites autorisées.',
            'integer' => 'Un nombre entier est attendu.', 'uuid' => 'La référence fournie est invalide.',
            'regex' => 'Le format de ce champ est invalide.', 'in' => 'La valeur sélectionnée n’est pas autorisée.',
            'distinct' => 'Une référence ne peut pas apparaître deux fois.', 'accepted' => 'Confirmez la modification du catalogue commun.'];
    }
}
