<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $participant_id
 * @property string $match_version
 * @property int $number
 * @property string $action
 * @property string $phase
 * @property string $reason
 * @property int $actor_user_id
 * @property array<string,mixed> $snapshot
 * @property array<string,mixed> $assessment
 * @property CarbonImmutable $created_at
 */
final class MatchLineupRevision extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'assessment' => 'encrypted:array', 'reason' => 'encrypted',
            'created_at' => 'immutable_datetime', 'number' => 'integer', 'actor_user_id' => 'integer'];
    }
}
