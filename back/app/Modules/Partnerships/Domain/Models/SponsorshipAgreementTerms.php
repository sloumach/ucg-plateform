<?php

namespace App\Modules\Partnerships\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $agreement_id
 * @property array<string, string|null> $terms
 * @property string $version
 * @property int $prepared_by
 */
final class SponsorshipAgreementTerms extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'terms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['terms' => 'encrypted:array', 'prepared_by' => 'integer'];
    }
}
