<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ContractDocumentScanFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $document_id
 * @property string $document_version_id
 * @property int $attempt
 * @property string $sha256
 * @property string $policy
 * @property string $status
 * @property string|null $reason
 * @property string|null $engine
 * @property int $requested_by
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $invalidated_at
 */
final class ContractDocumentScan extends Model
{
    /** @use HasFactory<ContractDocumentScanFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): ContractDocumentScanFactory
    {
        return ContractDocumentScanFactory::new();
    }

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['attempt' => 'integer', 'requested_by' => 'integer', 'requested_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'invalidated_at' => 'immutable_datetime'];
    }
}
