<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrganizationSupportEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = fn () => throw new DomainConflictException(__('platform.audit_immutable'), 'SUPPORT_AUDIT_IMMUTABLE');
        self::updating($refuse);
        self::deleting($refuse);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime'];
    }
}
