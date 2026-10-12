<?php

namespace App\Modules\People\Application\Validation;

use App\Modules\People\Application\Data\PersonLifecycleData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class PersonLifecycleInput
{
    public const array ROLES = ['player', 'substitute', 'captain', 'coach', 'analyst', 'staff'];

    public const int MAX_PERIODS = 1000;

    public const int MAX_ITEMS = 200;

    /** @return array<string,array<mixed>> */
    public static function periodRules(bool $creating): array
    {
        $rules = ['starts_at' => [$creating ? 'required' : 'sometimes', 'required', 'date_format:Y-m-d\\TH:i:sP'],
            'ends_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP'],
            'status' => ['sometimes', 'required', Rule::in(['active', 'cancelled'])]];
        if ($creating) {
            $rules['kind'] = ['required', Rule::in(['assignment', 'unavailability'])];
            $rules['role_key'] = ['required_if:kind,assignment', 'nullable', Rule::in(self::ROLES)];
        }

        return $rules;
    }

    /** @return array<string,array<mixed>> */
    public static function itemRules(bool $creating): array
    {
        return [...($creating ? ['kind' => ['required', Rule::in(['equipment', 'obligation'])]] : []),
            'title' => [$creating ? 'required' : 'sometimes', 'required', 'string', 'max:120', 'regex:/\\S/u'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'status' => ['sometimes', 'required', Rule::in(['open', 'resolved', 'waived'])],
            'due_at' => ['sometimes', 'nullable', 'date_format:Y-m-d\\TH:i:sP']];
    }

    /** @return array<string,array<mixed>> */
    public static function departureRules(): array
    {
        return ['reason_key' => ['required', Rule::in(['resignation', 'end_of_contract', 'decision', 'other'])],
            'confirm' => ['required', 'accepted'], 'acknowledge_pending' => ['sometimes', 'boolean']];
    }

    /** @return array<string,array<mixed>> */
    public static function pageRules(): array
    {
        return ['per_page' => ['sometimes', 'required', 'integer', 'between:1,100'], 'page' => ['sometimes', 'required', 'integer', 'between:1,10000']];
    }

    /** @return array<string,string> */
    public static function messages(): array
    {
        return [...PersonInput::messages(), 'date_format' => __('people_lifecycle.date'), 'between' => __('people_lifecycle.page'),
            'accepted' => __('people_lifecycle.confirm'), 'boolean' => __('people_lifecycle.boolean'), 'required_if' => __('people.validation.required')];
    }

    /** @param array<string,array<mixed>> $rules
     * @return array<string,mixed>
     */
    public static function validate(Factory $factory, PersonLifecycleData $data, array $rules, bool $creating): array
    {
        $unknown = array_diff(array_keys($data->attributes), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages(array_fill_keys($unknown, __('people.validation.immutable')));
        }
        if ($data->attributes === []) {
            throw ValidationException::withMessages(['lifecycle' => __('people.validation.empty')]);
        }
        $validated = $factory->make([...$data->attributes, 'version' => $data->version], [...$rules, 'version' => $creating ? ['prohibited'] : ['required', 'uuid']], self::messages())->validate();
        unset($validated['version']);

        return $validated;
    }

    public static function date(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }
}
