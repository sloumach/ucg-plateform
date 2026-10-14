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
 * @property int $version_number
 * @property string $sha256
 * @property CarbonImmutable $signed_on
 * @property int $recorded_by
 * @property CarbonImmutable $created_at
 */
final class ContractDocumentSignature extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['version_number' => 'integer', 'recorded_by' => 'integer', 'signed_on' => 'immutable_date', 'created_at' => 'immutable_datetime'];
    }
}
