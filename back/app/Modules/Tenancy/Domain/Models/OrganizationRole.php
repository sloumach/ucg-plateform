<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use Database\Factories\OrganizationRoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $key
 * @property string $name
 * @property list<string> $permissions
 * @property bool $active
 * @property bool $privileged
 * @property string $version
 */
final class OrganizationRole extends Model
{
    /** @use HasFactory<OrganizationRoleFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected static function newFactory(): OrganizationRoleFactory
    {
        return OrganizationRoleFactory::new();
    }

    protected function casts(): array
    {
        return ['permissions' => 'array', 'active' => 'boolean', 'privileged' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $role): void {
            if ($role->isDirty(['id', 'organization_id', 'key'])) {
                throw new DomainConflictException(__('authorization.identity'), 'ROLE_IDENTITY_IMMUTABLE');
            }
        });
    }
}
