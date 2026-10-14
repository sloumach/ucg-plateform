<?php

namespace App\Modules\Contracts\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $contract_id
 * @property string|null $document_id
 * @property string $scope_id
 * @property array<string, string|null> $terms
 * @property string $version
 * @property int $prepared_by
 */
final class ContractFinancialTerms extends Model
{
    use HasUuids;

    protected $table = 'contract_financial_terms';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'terms'];

    protected function casts(): array
    {
        return ['terms' => 'encrypted:array', 'prepared_by' => 'integer'];
    }
}
