<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarActivityFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $organization_id
 * @property string $title
 * @property string|null $description
 * @property string $kind
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $origin_timezone
 * @property string|null $team_id
 * @property string|null $location
 * @property string|null $url
 * @property string $visibility
 * @property int $created_by
 * @property string $version
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $cancelled_at
 * @property-read Collection<int,CalendarParticipant> $participants
 * @property-read Collection<int,CalendarReservation> $reservations
 */
final class CalendarActivity extends Model
{
    /** @use HasFactory<CalendarActivityFactory> */
    use HasFactory,HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by', 'sharing_authorized_by'];

    protected static function newFactory(): CalendarActivityFactory
    {
        return CalendarActivityFactory::new();
    }

    /** @return HasMany<CalendarParticipant,$this> */
    public function participants(): HasMany
    {
        return $this->hasMany(CalendarParticipant::class, 'activity_id')->orderBy('person_id');
    }

    /** @return HasMany<CalendarReservation,$this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(CalendarReservation::class, 'activity_id')->orderBy('resource_id');
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'description' => 'encrypted', 'location' => 'encrypted', 'url' => 'encrypted',
            'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'archived_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'created_by' => 'integer'];
    }
}
