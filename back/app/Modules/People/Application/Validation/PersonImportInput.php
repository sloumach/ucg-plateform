<?php

namespace App\Modules\People\Application\Validation;

use Illuminate\Contracts\Validation\Factory;
use Illuminate\Validation\ValidationException;

final class PersonImportInput
{
    public const array FIELDS = ['pseudonym', 'language', 'timezone', 'status', 'biography_public', 'first_name', 'last_name', 'contact_email', 'contact_phone', 'biography_internal'];

    public const array PERMISSIONS = ['sports.read', 'sports.write', 'administrative.read', 'administrative.write'];

    /** @return array<string,array<mixed>> */
    public static function mappingRules(): array
    {
        $rules = ['version' => ['required', 'uuid'], 'mapping' => ['required', 'array:'.implode(',', self::FIELDS)],
            'mapping.pseudonym' => ['required', 'integer', 'between:0,31'], 'defaults' => ['required', 'array:language,timezone,status']];
        foreach (array_diff(self::FIELDS, ['pseudonym']) as $field) {
            $rules['mapping.'.$field] = ['sometimes', 'integer', 'between:0,31'];
        }
        foreach (['language', 'timezone', 'status'] as $field) {
            $rules['defaults.'.$field] = PersonInput::rules(true)[$field];
        }

        return $rules;
    }

    /** @param array<string,mixed> $payload
     * @return array{mapping:array<string,int>,defaults:array<string,string>,version:string} */
    public static function mapping(Factory $validator, array $payload, int $columns): array
    {
        if (array_diff(array_keys($payload), ['mapping', 'defaults', 'version']) !== []) {
            throw ValidationException::withMessages(['mapping' => __('people.validation.immutable')]);
        }
        $data = $validator->make($payload, self::mappingRules(), PersonInput::messages())->validate();
        $mapping = [];
        foreach ($data['mapping'] as $field => $column) {
            if ((int) $column >= $columns) {
                throw ValidationException::withMessages(['mapping' => __('people_import.mapping')]);
            }
            $mapping[$field] = (int) $column;
        }
        if (count(array_unique($mapping)) !== count($mapping)) {
            throw ValidationException::withMessages(['mapping' => __('people_import.mapping')]);
        }

        return ['mapping' => $mapping, 'defaults' => $data['defaults'], 'version' => $data['version']];
    }
}
