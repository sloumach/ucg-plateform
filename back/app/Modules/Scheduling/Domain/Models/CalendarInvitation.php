<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $organization_id
 * @property string $activity_id
 * @property string $activity_version
 * @property string $person_id
 * @property int|null $recipient_id
 * @property int $issued_by
 * @property CarbonImmutable $response_deadline
 * @property list<int> $reminder_offsets
 * @property string $response
 * @property CarbonImmutable|null $responded_at
 * @property CarbonImmutable|null $sent_at
 * @property string|null $blocked_reason
 * @property string $version
 * @property-read CalendarActivity $activity
 */
final class CalendarInvitation extends Model
{
    /** @use HasFactory<CalendarInvitationFactory> */
    use HasFactory, HasUuids;

    protected static function newFactory(): CalendarInvitationFactory
    {
        return CalendarInvitationFactory::new();
    }

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'recipient_id', 'issued_by'];

    /** @return BelongsTo<CalendarActivity,$this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(CalendarActivity::class, 'activity_id');
    }

    /** @return HasMany<CalendarInvitationDelivery,$this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(CalendarInvitationDelivery::class, 'invitation_id');
    }

    public function isCurrent(): bool
    {
        return $this->activity->archived_at === null && $this->activity->version === $this->activity_version
            && $this->activity->participants->contains('person_id', $this->person_id);
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['recipient_id' => 'integer', 'issued_by' => 'integer', 'response_deadline' => 'immutable_datetime',
            'reminder_offsets' => 'array', 'responded_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime',
            'checked_at' => 'immutable_datetime'];
    }
}
