<?php

namespace App\Modules\Performance\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $observation_id */
final class StatisticalCalculationSource extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];
}
