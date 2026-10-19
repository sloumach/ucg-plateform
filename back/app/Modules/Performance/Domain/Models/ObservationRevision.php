<?php

namespace App\Modules\Performance\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $observation_id
 * @property int $number
 * @property ?string $base_version
 * @property string $availability
 * @property ?string $value
 * @property ?string $missing_reason
 * @property ?string $notes
 * @property string $status
 * @property string $reason
 * @property CarbonImmutable $created_at
 */
final class ObservationRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['value' => 'encrypted', 'missing_reason' => 'encrypted', 'notes' => 'encrypted', 'reason' => 'encrypted', 'number' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
