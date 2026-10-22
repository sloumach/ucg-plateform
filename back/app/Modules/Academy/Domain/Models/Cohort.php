<?php

namespace App\Modules\Academy\Domain\Models;

use Database\Factories\CohortFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $status
 * @property string $version
 */
final class Cohort extends Model
{
    /** @use HasFactory<CohortFactory> */
    use HasFactory, HasUuids;

    protected $table = 'academy_cohorts';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): CohortFactory
    {
        return CohortFactory::new();
    }
}
