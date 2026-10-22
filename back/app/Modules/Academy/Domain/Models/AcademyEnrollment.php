<?php

namespace App\Modules\Academy\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $cohort_id
 * @property string $cycle_id
 * @property string $person_id
 * @property string $role
 * @property string $starts_on
 * @property string $ends_on
 * @property string $status
 * @property string $version
 */
final class AcademyEnrollment extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];
}
