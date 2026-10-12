<?php

namespace App\Modules\People\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PersonPeriodFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $person_id
 * @property string $kind
 * @property string|null $role_key
 * @property string $status
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $version
 */
final class PersonPeriod extends Model
{
    /** @use HasFactory<PersonPeriodFactory> */
    use HasFactory, HasUuids, PersonLifecycleIdentity;

    protected $table = 'person_periods';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): PersonPeriodFactory
    {
        return PersonPeriodFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }
}
