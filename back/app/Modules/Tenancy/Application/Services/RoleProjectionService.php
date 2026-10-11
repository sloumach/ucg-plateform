<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;

final readonly class RoleProjectionService
{
    public function __construct(private TenantDataVisibility $visibility) {}

    /** @param list<OrganizationRole> $roles
     * @return list<array<string, mixed>>
     */
    public function project(TenantContext $context, array $roles): array
    {
        $fields = [];
        foreach (['id', 'organization_id', 'key', 'name', 'permissions', 'active', 'privileged', 'version'] as $name) {
            $fields[$name] = new FieldVisibility('roles.assign');
        }
        $records = array_map(fn (OrganizationRole $role): TenantDataRecord => new TenantDataRecord($role->organization_id,
            ['id' => $role->id, 'organization_id' => $role->organization_id, 'key' => $role->key, 'name' => $role->name,
                'permissions' => $role->permissions, 'active' => $role->active, 'privileged' => $role->privileged, 'version' => $role->version]), $roles);

        return $this->visibility->projectMany($context, $records, $fields);
    }
}
