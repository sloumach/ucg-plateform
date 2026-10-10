<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class OrganizationAccessEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        $refuse = fn () => throw new DomainConflictException(__('tenancy.errors.audit_immutable'), 'ORGANIZATION_AUDIT_IMMUTABLE');
        self::updating($refuse);
        self::deleting($refuse);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'occurred_at' => 'immutable_datetime'];
    }
}
