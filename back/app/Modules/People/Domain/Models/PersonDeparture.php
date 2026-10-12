<?php

namespace App\Modules\People\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PersonDepartureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $person_id
 * @property int|null $user_id
 * @property int $actor_user_id
 * @property string $reason_key
 * @property CarbonImmutable $departed_at
 * @property string $previous_version
 * @property string $person_version
 * @property int $pending_items_count
 */
final class PersonDeparture extends Model
{
    /** @use HasFactory<PersonDepartureFactory> */
    use HasFactory, HasUuids, PersonLifecycleIdentity;

    protected $table = 'person_departures';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'user_id', 'actor_user_id', 'reason_key', 'previous_version', 'pending_items_count'];

    protected static function newFactory(): PersonDepartureFactory
    {
        return PersonDepartureFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'actor_user_id' => 'integer', 'departed_at' => 'immutable_datetime', 'pending_items_count' => 'integer'];
    }
}
