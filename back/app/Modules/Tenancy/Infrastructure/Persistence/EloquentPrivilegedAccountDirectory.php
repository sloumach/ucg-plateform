<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Identity\Application\Contracts\PrivilegedAccountDirectory;
use App\Modules\Tenancy\Domain\Contracts\RoleRepository;
use App\Modules\Tenancy\Domain\MembershipRole;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;

final class EloquentPrivilegedAccountDirectory implements PrivilegedAccountDirectory
{
    public function __construct(private readonly RoleRepository $roles) {}

    public function hasOrganizationPrivileges(int $userId): bool
    {
        return Organization::query()->where('owner_user_id', $userId)->exists()
            || OrganizationMembership::query()->where('user_id', $userId)->effective(now())
                ->whereJsonContains('roles', MembershipRole::Administrator->value)->exists()
            || $this->roles->hasPrivilegedGrant($userId);
    }
}
