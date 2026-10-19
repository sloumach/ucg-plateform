<?php

namespace App\Modules\Performance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $metric_id
 * @property int $number
 * @property int $catalogue_number
 * @property array<string,mixed> $scope
 * @property CarbonImmutable $created_at */
final class MetricRevision extends Model
{
    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['scope' => 'array', 'number' => 'integer', 'catalogue_number' => 'integer', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
