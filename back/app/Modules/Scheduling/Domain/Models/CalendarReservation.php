<?php

namespace App\Modules\Scheduling\Domain\Models;

use Database\Factories\CalendarReservationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $id
 * @property string $organization_id
 * @property string $activity_id
 * @property string $resource_id
 * @property-read CalendarResource $resource
 */
final class CalendarReservation extends Model
{
    /** @use HasFactory<CalendarReservationFactory> */
    use HasFactory,HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function newFactory(): CalendarReservationFactory
    {
        return CalendarReservationFactory::new();
    }

    /** @return BelongsTo<CalendarResource,$this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(CalendarResource::class, 'resource_id');
    }
}
