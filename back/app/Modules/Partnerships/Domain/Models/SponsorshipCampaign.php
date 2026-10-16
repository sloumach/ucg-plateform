<?php

namespace App\Modules\Partnerships\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SponsorshipCampaignFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $agreement_id
 * @property string $title
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $responsible_user_id
 * @property string $status
 * @property string $version
 * @property int $created_by
 */
final class SponsorshipCampaign extends Model
{
    /** @use HasFactory<SponsorshipCampaignFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): SponsorshipCampaignFactory
    {
        return SponsorshipCampaignFactory::new();
    }

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'responsible_user_id' => 'integer', 'created_by' => 'integer'];
    }
}
