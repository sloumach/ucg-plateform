<?php

namespace App\Modules\Reporting\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;

/** @property string $id
 * @property string $organization_id
 * @property int $actor_user_id
 * @property string $card
 * @property string $view
 * @property string $timezone
 * @property string $from_date
 * @property string $to_date
 * @property string $status
 * @property string $version
 * @property string|null $failure_code
 * @property-read DashboardIndicatorRevision|null $current */
final class DashboardIndicator extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    /** @return HasOne<DashboardIndicatorRevision,$this> */
    public function current(): HasOne
    {
        return $this->hasOne(DashboardIndicatorRevision::class, 'indicator_id')->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('dashboard_indicator_revisions as newer')->whereColumn('newer.organization_id', 'dashboard_indicator_revisions.organization_id')->whereColumn('newer.indicator_id', 'dashboard_indicator_revisions.indicator_id')->whereColumn('newer.number', '>', 'dashboard_indicator_revisions.number'));
    }

    protected function casts(): array
    {
        return ['actor_user_id' => 'integer'];
    }
}
