<?php

namespace App\Modules\Tenancy\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class SensitiveAccessEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'organization_sensitive_access_events';

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new \LogicException('Sensitive access audit is append-only.'));
        self::deleting(fn () => throw new \LogicException('Sensitive access audit is append-only.'));
    }

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer', 'occurred_at' => 'immutable_datetime'];
    }
}
