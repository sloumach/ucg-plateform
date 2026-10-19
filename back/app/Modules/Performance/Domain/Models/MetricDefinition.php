<?php

namespace App\Modules\Performance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

/** @property string $id
 * @property string $game_id
 * @property string $code
 * @property int $created_by
 * @property CarbonImmutable $created_at
 * @property-read ?MetricRevision $current */
final class MetricDefinition extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @return HasOne<MetricRevision,$this> */
    public function current(): HasOne
    {
        return $this->hasOne(MetricRevision::class, 'metric_id')->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('metric_revisions as newer')->whereColumn('newer.metric_id', 'metric_revisions.metric_id')->whereColumn('newer.number', '>', 'metric_revisions.number'));
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }
}
