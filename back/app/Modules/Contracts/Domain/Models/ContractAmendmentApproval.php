<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $document_id
 * @property string $document_version_id
 * @property string|null $financial_version
 * @property int $number
 * @property int $approved_by
 * @property CarbonImmutable $created_at
 */
final class ContractAmendmentApproval extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'financial_version'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'approved_by' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
