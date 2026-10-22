<?php

namespace App\Modules\Academy\Domain\Models;

use Database\Factories\AcademyObjectiveFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $id
 * @property string $organization_id
 * @property string $cohort_id
 * @property string $cycle_id
 * @property string|null $target_enrollment_id
 * @property string $responsible_enrollment_id
 * @property string $title
 * @property string|null $description
 * @property string $due_on
 * @property string $status
 * @property string $version
 * @property AcademyEnrollment $responsible
 * @property AcademyEnrollment|null $target */
final class AcademyObjective extends Model
{
    /** @use HasFactory<AcademyObjectiveFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): AcademyObjectiveFactory
    {
        return AcademyObjectiveFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['title' => 'encrypted', 'description' => 'encrypted'];
    }

    /** @return BelongsTo<AcademyEnrollment, $this> */
    public function responsible(): BelongsTo
    {
        return $this->belongsTo(AcademyEnrollment::class, 'responsible_enrollment_id');
    }

    /** @return BelongsTo<AcademyEnrollment, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(AcademyEnrollment::class, 'target_enrollment_id');
    }
}
