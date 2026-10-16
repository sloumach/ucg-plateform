<?php

namespace App\Modules\Partnerships\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\SponsorshipAgreementFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $sponsor_id
 * @property string|null $opportunity_id
 * @property string $reference
 * @property string $title
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int $responsible_user_id
 * @property string|null $contract_id
 * @property string|null $contract_version
 * @property string $status
 * @property string $version
 * @property int $prepared_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property string|null $approved_financial_version
 */
final class SponsorshipAgreement extends Model
{
    /** @use HasFactory<SponsorshipAgreementFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): SponsorshipAgreementFactory
    {
        return SponsorshipAgreementFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'approved_at' => 'immutable_datetime', 'responsible_user_id' => 'integer', 'prepared_by' => 'integer', 'approved_by' => 'integer'];
    }
}
