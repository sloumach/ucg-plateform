<?php

namespace App\Modules\Partnerships\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $sponsor_id
 * @property string|null $contact_id
 * @property string|null $opportunity_id
 * @property string $channel
 * @property string $summary
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property int $created_by
 */
final class SponsorshipInteraction extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }
}
