<?php

namespace App\Modules\Academy\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property string $id
 * @property string $organization_id
 * @property string $cycle_id
 * @property string $session_id
 * @property string $enrollment_id
 * @property string $status
 * @property int|null $late_minutes
 * @property string|null $change_reason
 * @property string $version
 * @property AcademyEnrollment $enrollment */
final class AcademyAttendance extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['change_reason' => 'encrypted', 'late_minutes' => 'integer'];
    }

    /** @return BelongsTo<AcademyEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(AcademyEnrollment::class, 'enrollment_id');
    }
}
