<?php

namespace App\Modules\Tenancy\Application\Validation;

final class MembershipInput
{
    /** @return array<string, array<mixed>> */
    public static function periodRules(): array
    {
        return ['roles' => ['required', 'array', 'min:1', 'max:2'],
            'roles.*' => ['required', 'string', 'distinct', 'in:member,administrator'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'after:starts_at']];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [...OrganizationInput::messages(),
            'email.email' => __('tenancy.validation.email'),
            'roles.array' => __('tenancy.validation.roles'),
            'roles.min' => __('tenancy.validation.roles'),
            'roles.max' => __('tenancy.validation.roles'),
            'roles.*.distinct' => __('tenancy.validation.roles'),
            'roles.*.in' => __('tenancy.validation.choice'),
            '*.date_format' => __('tenancy.validation.date'),
            'ends_at.after' => __('tenancy.validation.period'),
            'confirm.accepted' => __('tenancy.validation.confirm')];
    }

    private function __construct() {}
}
