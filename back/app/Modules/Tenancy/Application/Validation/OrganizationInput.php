<?php

namespace App\Modules\Tenancy\Application\Validation;

use Illuminate\Validation\Rule;

final class OrganizationInput
{
    /** @return array<string, array<mixed>> */
    public static function detailsRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160', 'regex:/\S/u'],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'language' => ['required', 'string', Rule::in(config('tenancy.languages', ['fr']))],
            'country' => ['required', 'string', Rule::in(explode(' ', config('tenancy.country_codes')))],
            'settings' => ['required', 'array:week_starts_on,date_format'],
            'settings.week_starts_on' => ['required', 'integer', 'between:1,7'],
            'settings.date_format' => ['required', 'string', Rule::in(['d/m/Y', 'Y-m-d'])],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'required' => __('tenancy.validation.required'),
            'string' => __('tenancy.validation.string'),
            'max' => __('tenancy.validation.max'),
            'in' => __('tenancy.validation.choice'),
            'integer' => __('tenancy.validation.integer'),
            'between' => __('tenancy.validation.between'),
            'array' => __('tenancy.validation.settings'),
            'regex' => __('tenancy.validation.format'),
            'timezone' => __('tenancy.validation.timezone'),
            'uuid' => __('tenancy.validation.uuid'),
            'prohibited' => __('tenancy.validation.immutable'),
        ];
    }
}
