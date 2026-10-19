<?php

namespace App\Modules\Performance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $generation
 * @property int $number
 * @property string $engine_version
 * @property array<string,mixed> $result
 * @property array<string,string> $source_versions
 * @property CarbonImmutable $created_at
 */
final class StatisticalCalculationRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'source_versions', 'request_id'];

    protected function casts(): array
    {
        return ['number' => 'integer', 'result' => 'encrypted:array', 'source_versions' => 'encrypted:array', 'created_at' => 'immutable_datetime'];
    }
}
