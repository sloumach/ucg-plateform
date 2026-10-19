<?php

namespace App\Modules\Performance\Domain\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

/** @property string $id
 * @property string $organization_id
 * @property string $metric_id
 * @property string $metric_revision_id
 * @property string $game_id
 * @property string $person_id
 * @property ?string $match_id
 * @property ?string $match_game_id
 * @property string $source_kind
 * @property string $acquired_via
 * @property ?string $origin_reference
 * @property string $source_label
 * @property string $period_start
 * @property string $period_end
 * @property string $observed_on
 * @property int $sample_size
 * @property array<string,mixed> $context
 * @property-read ?ObservationRevision $current
 */
final class StatisticalObservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return HasOne<ObservationRevision,$this> */
    public function current(): HasOne
    {
        return $this->hasOne(ObservationRevision::class, 'observation_id')->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('observation_revisions as newer')->whereColumn('newer.organization_id', 'observation_revisions.organization_id')->whereColumn('newer.observation_id', 'observation_revisions.observation_id')->whereColumn('newer.number', '>', 'observation_revisions.number'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['source_label' => 'encrypted', 'context' => 'encrypted:array', 'sample_size' => 'integer'];
    }
}
