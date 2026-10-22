<?php

namespace App\Modules\Academy\Domain\Models;

use Database\Factories\AcademyCycleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $cohort_id
 * @property string $name
 * @property string $starts_on
 * @property string $ends_on
 * @property string $timezone
 * @property list<array{day: int, starts_at: string, ends_at: string}> $timetable
 * @property string $status
 * @property string $version
 */
final class AcademyCycle extends Model
{
    /** @use HasFactory<AcademyCycleFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['timetable' => 'array'];
    }

    protected static function newFactory(): AcademyCycleFactory
    {
        return AcademyCycleFactory::new();
    }
}
