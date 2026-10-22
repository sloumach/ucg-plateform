<?php

namespace App\Modules\Academy\Domain\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AcademySessionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property string $id
 * @property string $organization_id
 * @property string $cohort_id
 * @property string $cycle_id
 * @property string $activity_id
 * @property string $activity_version
 * @property string $responsible_enrollment_id
 * @property string $title
 * @property list<array{title: string, instructions: string|null, minutes: int}> $exercises
 * @property string|null $report
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property string $status
 * @property string $version
 * @property AcademyEnrollment $responsibleEnrollment
 * @property int $attendances_count */
final class AcademySession extends Model
{
    /** @use HasFactory<AcademySessionFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): AcademySessionFactory
    {
        return AcademySessionFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'exercises' => 'encrypted:array', 'report' => 'encrypted', 'starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AcademyEnrollment, $this> */
    public function responsibleEnrollment(): BelongsTo
    {
        return $this->belongsTo(AcademyEnrollment::class, 'responsible_enrollment_id');
    }

    /** @return HasMany<AcademyAttendance, $this> */
    public function attendances(): HasMany
    {
        return $this->hasMany(AcademyAttendance::class, 'session_id');
    }
}
