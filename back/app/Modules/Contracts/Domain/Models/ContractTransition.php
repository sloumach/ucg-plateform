<?php

namespace App\Modules\Contracts\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $contract_version
 * @property int $actor_user_id
 * @property CarbonImmutable $occurred_at
 */
final class ContractTransition extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'actor_user_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new DomainConflictException(__('managed_contracts.history'), 'CONTRACT_HISTORY_RETAINED'));
        self::deleting(fn () => throw new DomainConflictException(__('managed_contracts.history'), 'CONTRACT_HISTORY_RETAINED'));
    }
}
