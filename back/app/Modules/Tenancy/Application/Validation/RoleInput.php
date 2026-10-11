<?php

namespace App\Modules\Tenancy\Application\Validation;

use App\Modules\Tenancy\Domain\Permission;
use Illuminate\Validation\Rule;

final class RoleInput
{
    public const MAX_ROLES = 100;

    public const SCOPE_TYPES = ['organization', 'team', 'game', 'cohort', 'event'];

    /** @return array<string, array<mixed>> */
    public static function roleRules(): array
    {
        return ['key' => ['required', 'string', 'max:80', 'regex:/\A[a-z][a-z0-9]*(?:-[a-z0-9]+)*\z/', Rule::notIn(['owner', 'administrator', 'member', 'superadmin', 'platform'])],
            'name' => ['required', 'string', 'max:120', 'regex:/\S/u'], 'active' => ['required', 'boolean'],
            'permissions' => ['required', 'array', 'min:1', 'max:16'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in(array_map(fn (Permission $permission): string => $permission->value, array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->configurable())))],
            'organization_id' => ['prohibited'], 'id' => ['prohibited'], 'privileged' => ['prohibited']];
    }

    /** @return array<string, array<mixed>> */
    public static function assignmentRules(): array
    {
        return ['role_id' => ['required', 'uuid'], 'user_id' => ['required', 'integer', 'min:1'],
            'scope_type' => ['required', 'string', Rule::in(self::SCOPE_TYPES)], 'scope_id' => ['required', 'uuid'],
            'status' => ['required', 'in:active,suspended,revoked'],
            'starts_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'ends_at' => ['nullable', 'date_format:Y-m-d\TH:i:sP', 'after:starts_at'],
            'organization_id' => ['prohibited'], 'membership_id' => ['prohibited'], 'id' => ['prohibited']];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [...MembershipInput::messages(), 'permissions.*.in' => __('authorization.permission_invalid'),
            'permissions.*.distinct' => __('authorization.permission_invalid'), 'key.not_in' => __('authorization.key_reserved'),
            'scope_type.in' => __('authorization.scope_invalid'), 'version.required' => __('authorization.version_required')];
    }
}
