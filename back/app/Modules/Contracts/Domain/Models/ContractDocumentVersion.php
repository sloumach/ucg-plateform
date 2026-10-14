<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $document_id
 * @property string $artifact_id
 * @property int $number
 * @property string $purpose
 * @property string $filename
 * @property string $sha256
 * @property int $bytes
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property ContractDocumentScan|null $latestScan
 */
final class ContractDocumentVersion extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'artifact_id'];

    protected function casts(): array
    {
        return ['filename' => 'encrypted', 'number' => 'integer', 'bytes' => 'integer', 'created_by' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasOne<ContractDocumentScan, $this> */
    public function latestScan(): HasOne
    {
        return $this->hasOne(ContractDocumentScan::class, 'document_version_id')->where('attempt', function (Builder $query): void {
            $query->selectRaw('MAX(s.attempt)')->from('contract_document_scans as s')
                ->whereColumn('s.organization_id', 'contract_document_scans.organization_id')
                ->whereColumn('s.document_version_id', 'contract_document_scans.document_version_id');
        });
    }
}
