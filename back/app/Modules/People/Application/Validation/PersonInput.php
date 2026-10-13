<?php

namespace App\Modules\People\Application\Validation;

use App\Modules\People\Domain\PersonStatus;
use Illuminate\Validation\Rule;

final class PersonInput
{
    public const array SPORTS_FIELDS = ['pseudonym', 'language', 'timezone', 'status', 'biography_public'];

    public const array ADMINISTRATIVE_FIELDS = ['user_id', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal', 'birth_date'];

    /** @return array<string, array<mixed>> */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'pseudonym' => [$required, 'required', 'string', 'max:80', 'regex:/\S/u'],
            'language' => [$required, 'required', 'string', Rule::in(config('tenancy.languages', ['fr']))],
            'timezone' => [$required, 'required', 'string', 'max:64', 'timezone:all'],
            'status' => [$required, 'required', 'string', Rule::in(array_column(PersonStatus::cases(), 'value'))],
            'biography_public' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email:rfc', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()-]{3,32}$/D'],
            'biography_internal' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'birth_date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return ['required' => __('people.validation.required'), 'string' => __('people.validation.string'),
            'max' => __('people.validation.max'), 'in' => __('people.validation.choice'),
            'enum' => __('people.validation.choice'), 'integer' => __('people.validation.integer'),
            'min' => __('people.validation.min'), 'regex' => __('people.validation.format'),
            'timezone' => __('people.validation.timezone'), 'email' => __('people.validation.email'),
            'uuid' => __('people.validation.uuid'), 'prohibited' => __('people.validation.immutable'),
            'birth_date.date_format' => 'Indiquez une date de naissance valide au format AAAA-MM-JJ.',
            'birth_date.after_or_equal' => 'La date de naissance doit être comprise entre 1900 et aujourd’hui.',
            'birth_date.before_or_equal' => 'La date de naissance ne peut pas être future.'];
    }
}
