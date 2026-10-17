<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarChangeRecipientFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $id
 * @property string $organization_id
 * @property string $change_id
 * @property string $person_id
 * @property int|null $recipient_id
 * @property CarbonImmutable|null $delivered_at
 * @property string|null $blocked_reason
 * @property-read CalendarChange $change
 */
final class CalendarChangeRecipient extends Model
{
    /** @use HasFactory<CalendarChangeRecipientFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'recipient_id', 'person_id', 'run_id'];

    protected static function newFactory(): CalendarChangeRecipientFactory
    {
        return CalendarChangeRecipientFactory::new();
    }

    /** @return BelongsTo<CalendarChange,$this> */
    public function change(): BelongsTo
    {
        return $this->belongsTo(CalendarChange::class, 'change_id');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['recipient_id' => 'integer', 'created_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime', 'delivered_at' => 'immutable_datetime'];
    }
}
