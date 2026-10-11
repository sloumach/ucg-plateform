<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationRoleAssignmentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $role_id
 * @property int $user_id
 * @property string|null $membership_id
 * @property string $scope_type
 * @property string $scope_id
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $version
 * @property-read OrganizationRole $role
 */
final class OrganizationRoleAssignment extends Model
{
    /** @use HasFactory<OrganizationRoleAssignmentFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected static function newFactory(): OrganizationRoleAssignmentFactory
    {
        return OrganizationRoleAssignmentFactory::new();
    }

    protected function casts(): array
    {
        return ['user_id' => 'integer', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<OrganizationRole, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(OrganizationRole::class);
    }

    protected static function booted(): void
    {
        self::updating(function (self $assignment): void {
            if ($assignment->isDirty(['id', 'organization_id', 'role_id', 'user_id', 'membership_id', 'scope_type', 'scope_id'])) {
                throw new DomainConflictException(__('authorization.identity'), 'ROLE_ASSIGNMENT_IDENTITY_IMMUTABLE');
            }
        });
    }
}
