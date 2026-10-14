<?php

namespace App\Modules\Contracts\Application\Validation;

use App\Modules\Contracts\Domain\ContractLifecycle;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ContractInput
{
    public const int MAX_CONTRACTS = 1000;

    public const int MAX_TRANSITIONS = 200;

    /** @return array<string, array<mixed>> */
    public static function pageRules(bool $history = false): array
    {
        return ['page' => ['sometimes', 'integer', 'between:1,100000'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            ...($history ? [] : ['q' => ['sometimes', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/D'],
                'type' => ['sometimes', Rule::in(ContractLifecycle::TYPES)], 'status' => ['sometimes', Rule::in(ContractLifecycle::STATUSES)],
                'responsible_person_id' => ['sometimes', 'uuid']])];
    }

    /** @return array<string, array<mixed>> */
    public static function saveRules(bool $creating): array
    {
        return [...($creating ? ['reference' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
            'type' => ['required', 'string', Rule::in(ContractLifecycle::TYPES)]] : ['version' => ['required', 'uuid']]),
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:160', 'regex:/\\S/u'],
            'responsible_person_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'signature_separation_required' => ['sometimes', 'boolean:strict'],
            'starts_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'ends_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01'],
            'parties' => [$creating ? 'required' : 'sometimes', 'array', 'list', 'min:1', 'max:10'],
            'parties.*' => ['array:kind,person_id,name'],
            'parties.*.kind' => ['required', 'string', Rule::in(['person', 'external'])],
            'parties.*.person_id' => ['required_if:parties.*.kind,person', 'nullable', 'uuid', 'distinct:ignore_case'],
            'parties.*.name' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'obligations' => ['sometimes', 'array', 'list', 'max:50'],
            'obligations.*' => ['array:title,due_on'],
            'obligations.*.title' => ['required', 'string', 'max:160', 'regex:/\\S/u'],
            'obligations.*.due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01']];
    }

    /** @return array<string, array<mixed>> */
    public static function transitionRules(): array
    {
        return ['version' => ['required', 'uuid'], 'status' => ['required', 'string', Rule::in(ContractLifecycle::STATUSES)],
            'signed_on' => ['required_if:status,signed', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'signature_reference' => ['required_if:status,signed', 'string', 'max:160', 'regex:/\\S/u'],
            'confirm_signature_received' => ['accepted_if:status,signed'],
            'terminated_on' => ['required_if:status,terminated', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today']];
    }

    /** @return array<string, array<mixed>> */
    public static function obligationRules(): array
    {
        return ['version' => ['required', 'uuid'], 'contract_version' => ['required', 'uuid'],
            'status' => ['required', Rule::in(['pending', 'completed', 'waived'])]];
    }

    /** @param array<string, mixed> $input
     * @param  array<string, array<mixed>>  $rules
     * @param  array<string, string>  $messages
     * @return array<string, mixed>
     */
    public static function validate(Factory $factory, array $input, array $rules, array $messages = []): array
    {
        foreach (['starts_on', 'ends_on'] as $nullable) {
            if (($input[$nullable] ?? null) === '') {
                $input[$nullable] = null;
            }
        }
        $roots = array_filter(array_keys($rules), fn (string $field): bool => ! str_contains($field, '.'));
        $unknown = array_diff(array_keys($input), $roots);
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('managed_contracts.unknown')));
        }

        return $factory->make($input, $rules, [...self::messages(), ...$messages])->validate();
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['required' => 'Ce champ est obligatoire.', 'required_if' => 'Renseignez la personne liée.',
            'string' => 'Ce champ doit être un texte.', 'array' => 'La liste fournie est invalide.',
            'list' => 'Une liste ordonnée est attendue.', 'min' => 'Ajoutez au moins une partie.',
            'max' => 'Ce champ dépasse la limite autorisée.', 'integer' => 'Un nombre entier est attendu.',
            'between' => 'Ce champ est hors des limites autorisées.', 'uuid' => 'La référence fournie est invalide.',
            'regex' => 'Le format de ce champ est invalide.', 'in' => 'La valeur sélectionnée n’est pas autorisée.',
            'distinct' => 'Une personne ne peut figurer deux fois dans les parties.',
            'date_format' => 'Indiquez une date valide au format année-mois-jour.',
            'before_or_equal' => 'Cette date ne peut pas être dans le futur.',
            'after_or_equal' => 'Cette date est antérieure à la borne autorisée.',
            'accepted_if' => 'Confirmez la réception d’une signature obtenue hors de la plateforme.',
            'signed_on.required_if' => __('managed_contracts.signature'),
            'signature_reference.required_if' => __('managed_contracts.signature'),
            'terminated_on.required_if' => __('managed_contracts.termination')];
    }
}
