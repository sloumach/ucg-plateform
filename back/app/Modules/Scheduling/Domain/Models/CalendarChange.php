<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarChangeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $organization_id
 * @property string $activity_id
 * @property string $previous_version
 * @property string $activity_version
 * @property int $actor_user_id
 * @property string $kind
 * @property string $title
 * @property string $reason
 * @property CarbonImmutable $previous_starts_at
 * @property CarbonImmutable $previous_ends_at
 * @property string $previous_timezone
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $origin_timezone
 * @property CarbonImmutable $created_at
 * @property-read CalendarActivity $activity
 */
final class CalendarChange extends Model
{
    /** @use HasFactory<CalendarChangeFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id'];

    protected static function newFactory(): CalendarChangeFactory
    {
        return CalendarChangeFactory::new();
    }

    /** @return BelongsTo<CalendarActivity,$this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(CalendarActivity::class, 'activity_id');
    }

    /** @return HasMany<CalendarChangeRecipient,$this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CalendarChangeRecipient::class, 'change_id');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'reason' => 'encrypted', 'actor_user_id' => 'integer', 'previous_starts_at' => 'immutable_datetime',
            'previous_ends_at' => 'immutable_datetime', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }
}
