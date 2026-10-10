<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OrganizationLifecycleEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new DomainConflictException(__('tenancy.errors.audit_immutable'), 'ORGANIZATION_AUDIT_IMMUTABLE'));
        static::deleting(fn () => throw new DomainConflictException(__('tenancy.errors.audit_immutable'), 'ORGANIZATION_AUDIT_IMMUTABLE'));
    }
}
