<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $base_version
 * @property string $match_version
 * @property int $number
 * @property string $action
 * @property string $outcome
 * @property string $from_status
 * @property string $to_status
 * @property ?string $submission_id
 * @property ?string $proof_reference
 * @property ?array<string,mixed> $snapshot
 * @property ?list<int> $contributors
 * @property string $reason
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at
 */
final class MatchResultEvent extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'contributors'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'actor_user_id' => 'integer', 'proof_reference' => 'encrypted', 'snapshot' => 'encrypted:array',
            'contributors' => 'encrypted:array', 'reason' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }
}
