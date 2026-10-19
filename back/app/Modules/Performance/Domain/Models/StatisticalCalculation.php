<?php

namespace App\Modules\Performance\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

/** @property string $id
 * @property string $organization_id
 * @property int $actor_user_id
 * @property string $metric_id
 * @property string $metric_revision_id
 * @property string $status
 * @property string $version
 * @property int $source_count
 * @property array<string,mixed> $definition
 * @property string|null $failure_code
 * @property-read StatisticalCalculationRevision|null $current
 */
final class StatisticalCalculation extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id', 'fingerprint', 'definition'];

    /** @return HasOne<StatisticalCalculationRevision,$this> */
    public function current(): HasOne
    {
        return $this->hasOne(StatisticalCalculationRevision::class, 'calculation_id')->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('statistical_calculation_revisions as newer')->whereColumn('newer.organization_id', 'statistical_calculation_revisions.organization_id')->whereColumn('newer.calculation_id', 'statistical_calculation_revisions.calculation_id')->whereColumn('newer.number', '>', 'statistical_calculation_revisions.number'));
    }

    protected function casts(): array
    {
        return ['definition' => 'encrypted:array', 'actor_user_id' => 'integer', 'source_count' => 'integer'];
    }
}
