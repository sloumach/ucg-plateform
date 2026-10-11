<?php

namespace App\Modules\Identity\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Support\Audit\AuditState;
use Database\Factories\IdentityAuditEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class IdentityAuditEvent extends Model
{
    /** @use HasFactory<IdentityAuditEventFactory> */
    use HasFactory,HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function newFactory(): IdentityAuditEventFactory
    {
        return IdentityAuditEventFactory::new();
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
