<?php

namespace App\Modules\Contracts\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Database\Factories\ContractRecordFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $type
 * @property string $title
 * @property string $responsible_person_id
 * @property string $responsible_name
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property string $status
 * @property string $version
 * @property int $created_by
 * @property int $prepared_by
 * @property bool $signature_separation_required
 * @property int|null $signature_recorded_by
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $signed_on
 * @property string|null $signature_reference
 * @property CarbonImmutable|null $signature_recorded_at
 * @property CarbonImmutable|null $terminated_on
 * @property int $parties_count
 * @property int $obligations_count
 * @property int $pending_obligations_count
 * @property int $transitions_count
 * @property bool $financial_terms_present
 * @property bool $financial_documents_restricted
 * @property Collection<int, ContractParty> $parties
 * @property Collection<int, ContractObligation> $obligations
 */
final class ContractRecord extends Model
{
    /** @use HasFactory<ContractRecordFactory> */
    use HasFactory, HasUuids;

    protected $table = 'contracts';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): ContractRecordFactory
    {
        return ContractRecordFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'responsible_name' => 'encrypted', 'signature_reference' => 'encrypted',
            'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'signed_on' => 'immutable_date', 'terminated_on' => 'immutable_date',
            'approved_at' => 'immutable_datetime', 'signature_recorded_at' => 'immutable_datetime',
            'created_by' => 'integer', 'prepared_by' => 'integer', 'approved_by' => 'integer',
            'signature_separation_required' => 'boolean', 'signature_recorded_by' => 'integer'];
    }

    /** @return HasMany<ContractParty, $this> */
    public function parties(): HasMany
    {
        return $this->hasMany(ContractParty::class, 'contract_id')->orderBy('position');
    }

    /** @return HasMany<ContractObligation, $this> */
    public function obligations(): HasMany
    {
        return $this->hasMany(ContractObligation::class, 'contract_id')->orderBy('position');
    }

    /** @return HasMany<ContractTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(ContractTransition::class, 'contract_id');
    }

    /** @return HasMany<ContractFinancialTerms, $this> */
    public function financialTerms(): HasMany
    {
        return $this->hasMany(ContractFinancialTerms::class, 'contract_id');
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('managed_contracts.history'), 'CONTRACT_HISTORY_RETAINED'));
        self::updating(function (self $contract): void {
            if ($contract->isDirty(['id', 'organization_id', 'reference', 'type', 'created_by'])) {
                throw new DomainConflictException(__('managed_contracts.immutable'), 'CONTRACT_IDENTITY_IMMUTABLE');
            }
            if ($contract->getOriginal('status') === 'archived' && $contract->isDirty()) {
                throw new DomainConflictException(__('managed_contracts.archived'), 'CONTRACT_ARCHIVED');
            }
        });
    }
}
