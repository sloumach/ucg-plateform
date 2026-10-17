<?php

namespace App\Modules\Scheduling\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CalendarConflictOverrideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $activity_id
 * @property string $activity_version
 * @property string $reason
 * @property array<string,mixed> $snapshot
 * @property int $conflict_count
 * @property CarbonImmutable $created_at
 */
final class CalendarConflictOverride extends Model
{
    /** @use HasFactory<CalendarConflictOverrideFactory> */
    use HasFactory,HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id', 'reason', 'snapshot'];

    protected static function newFactory(): CalendarConflictOverrideFactory
    {
        return CalendarConflictOverrideFactory::new();
    }

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'snapshot' => 'encrypted:array', 'conflict_count' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
