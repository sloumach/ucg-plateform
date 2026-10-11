<?php

namespace App\Modules\Identity\Domain\Models;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PlatformRoleEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = fn () => throw new DomainConflictException(__('platform.audit_immutable'), 'PLATFORM_AUDIT_IMMUTABLE');
        self::updating($refuse);
        self::deleting($refuse);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before_active' => 'boolean', 'after_active' => 'boolean', 'occurred_at' => 'immutable_datetime'];
    }
}
