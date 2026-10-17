<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AvailabilityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $person_id
 * @property string $status
 * @property string|null $comment
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $origin_timezone
 * @property CarbonImmutable|null $cancelled_at
 * @property string $version
 * @property int $number
 * @property CarbonImmutable $created_at
 */
final class Availability extends Model
{
    /** @use HasFactory<AvailabilityFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'created_by', 'comment'];

    protected static function newFactory(): AvailabilityFactory
    {
        return AvailabilityFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'comment' => 'encrypted', 'number' => 'integer'];
    }
}
