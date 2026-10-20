<?php

namespace App\Modules\Reporting\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $indicator_id
 * @property int $number
 * @property string $generation
 * @property int $source_version
 * @property string $engine_version
 * @property array<string,mixed> $result
 * @property CarbonImmutable $evaluated_at
 * @property CarbonImmutable $expires_at */
final class DashboardIndicatorRevision extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['result' => 'encrypted:array', 'number' => 'integer', 'source_version' => 'integer', 'evaluated_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
