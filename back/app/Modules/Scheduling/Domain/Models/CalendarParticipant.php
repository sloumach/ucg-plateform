<?php

namespace App\Modules\Scheduling\Domain\Models;

use Database\Factories\CalendarParticipantFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $activity_id
 * @property string $person_id
 */
final class CalendarParticipant extends Model
{
    /** @use HasFactory<CalendarParticipantFactory> */
    use HasFactory,HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected static function newFactory(): CalendarParticipantFactory
    {
        return CalendarParticipantFactory::new();
    }
}
