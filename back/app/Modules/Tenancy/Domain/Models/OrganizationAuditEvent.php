<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Support\Audit\AuditState;
use Database\Factories\OrganizationAuditEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class OrganizationAuditEvent extends Model
{
    /** @use HasFactory<OrganizationAuditEventFactory> */
    use HasFactory,HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function newFactory(): OrganizationAuditEventFactory
    {
        return OrganizationAuditEventFactory::new();
    }

    protected static function booted(): void
    {
        $refuse = fn () => throw new DomainConflictException(__('audit.immutable'), 'AUDIT_IMMUTABLE');
        self::updating($refuse);
        self::deleting($refuse);
        self::creating(function (self $event): void {
            AuditState::project($event->before ?? [], true);
            AuditState::project($event->after ?? [], true);
        });
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['before' => 'array', 'after' => 'array', 'occurred_at' => 'immutable_datetime', 'actor_user_id' => 'integer'];
    }
}
