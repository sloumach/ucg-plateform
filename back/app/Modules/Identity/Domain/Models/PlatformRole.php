<?php

namespace App\Modules\Identity\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\PlatformRoleType;
use Database\Factories\PlatformRoleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property PlatformRoleType $role
 * @property bool $active
 */
final class PlatformRole extends Model
{
    /** @use HasFactory<PlatformRoleFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected static function newFactory(): PlatformRoleFactory
    {
        return PlatformRoleFactory::new();
    }

    protected static function booted(): void
    {
        self::updating(function (self $role): void {
            if ($role->isDirty(['id', 'user_id']) || ($role->isDirty(['role', 'active']) && ! $role->isDirty('version'))) {
                throw new DomainConflictException(__('platform.identity_immutable'), 'PLATFORM_ROLE_IDENTITY_IMMUTABLE');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['role' => PlatformRoleType::class, 'active' => 'boolean'];
    }
}
