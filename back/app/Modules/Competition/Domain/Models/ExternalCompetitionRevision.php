<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $competition_id
 * @property int $number
 * @property ?string $base_version
 * @property string $version
 * @property array<string,mixed> $snapshot
 * @property list<string> $changed_fields
 * @property string $reason
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at */
final class ExternalCompetitionRevision extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'snapshot', 'changed_fields', 'reason'];

    /**
     * @return array<string,string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'snapshot' => 'encrypted:array', 'changed_fields' => 'encrypted:array', 'reason' => 'encrypted', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
