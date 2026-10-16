<?php

namespace App\Modules\Partnerships\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $campaign_id
 * @property string|null $deliverable_id
 * @property string $action
 * @property string|null $from_status
 * @property string $to_status
 * @property string $record_version
 * @property int $actor_user_id
 * @property CarbonImmutable $occurred_at
 */
final class SponsorshipCampaignEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'delivery_snapshot'];

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'actor_user_id' => 'integer', 'delivery_snapshot' => 'encrypted:array'];
    }
}
