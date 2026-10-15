<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $plan_id
 * @property string $person_id
 * @property int|null $recipient_id
 * @property string $occurrence
 * @property string $kind
 * @property CarbonImmutable $due_on
 * @property int $offset_days
 * @property string $in_app_status
 * @property string $email_status
 * @property string|null $reason
 * @property int $attempts
 * @property string $version
 * @property string $run_id
 * @property CarbonImmutable|null $email_started_at
 * @property CarbonImmutable|null $email_sent_at
 * @property CarbonImmutable|null $read_at
 */
final class ContractReminderDelivery extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'recipient_id', 'person_id', 'occurrence'];

    protected function casts(): array
    {
        return ['recipient_id' => 'integer', 'offset_days' => 'integer', 'attempts' => 'integer', 'due_on' => 'immutable_date',
            'email_started_at' => 'immutable_datetime', 'email_sent_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }
}
