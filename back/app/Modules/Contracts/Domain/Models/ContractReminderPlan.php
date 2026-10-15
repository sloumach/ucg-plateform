<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property bool $enabled
 * @property CarbonImmutable|null $notice_on
 * @property CarbonImmutable|null $renewal_on
 * @property list<int> $offsets
 * @property list<string> $channels
 * @property string $version
 * @property CarbonImmutable|null $checked_at
 * @property int $updated_by
 */
final class ContractReminderPlan extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'notice_on' => 'immutable_date', 'renewal_on' => 'immutable_date',
            'offsets' => 'array', 'channels' => 'array', 'updated_by' => 'integer', 'checked_at' => 'immutable_datetime'];
    }
}
