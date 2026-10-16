<?php

namespace App\Modules\Partnerships\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SponsorshipDeliverableFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $agreement_id
 * @property string $campaign_id
 * @property string $exposure_id
 * @property string $kind
 * @property string $title
 * @property int $responsible_user_id
 * @property CarbonImmutable $due_at
 * @property string $status
 * @property string|null $proof_reference
 * @property CarbonImmutable|null $delivered_at
 * @property int|null $delivered_by
 * @property CarbonImmutable|null $validated_at
 * @property int|null $validated_by
 * @property string|null $validated_agreement_version
 * @property string|null $validated_exposure_version
 * @property int $prepared_by
 * @property string $version
 */
final class SponsorshipDeliverable extends Model
{
    /** @use HasFactory<SponsorshipDeliverableFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'proof_reference'];

    protected static function newFactory(): SponsorshipDeliverableFactory
    {
        return SponsorshipDeliverableFactory::new();
    }

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['due_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime', 'validated_at' => 'immutable_datetime', 'proof_reference' => 'encrypted',
            'responsible_user_id' => 'integer', 'delivered_by' => 'integer', 'validated_by' => 'integer', 'prepared_by' => 'integer'];
    }
}
