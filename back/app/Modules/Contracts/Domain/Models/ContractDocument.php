<?php

namespace App\Modules\Contracts\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ContractDocumentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string $reference
 * @property string $kind
 * @property string $title
 * @property string|null $original_document_id
 * @property CarbonImmutable|null $effective_on
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property int $versions_count
 * @property ContractDocumentSignature|null $signature
 */
final class ContractDocument extends Model
{
    /** @use HasFactory<ContractDocumentFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): ContractDocumentFactory
    {
        return ContractDocumentFactory::new();
    }

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['title' => 'encrypted', 'effective_on' => 'immutable_date', 'created_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }

    /** @return HasOne<ContractDocumentSignature, $this> */
    public function signature(): HasOne
    {
        return $this->hasOne(ContractDocumentSignature::class, 'document_id');
    }

    /** @return HasMany<ContractDocumentVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ContractDocumentVersion::class, 'document_id');
    }
}
