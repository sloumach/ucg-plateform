<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $match_version
 * @property int $number
 * @property string $outcome
 * @property ?string $map
 * @property ?string $mode
 * @property string $game_patch
 * @property string $reason
 * @property int $actor_user_id
 * @property array<int,array{participant_id:string,label:string,score:?int,lineup_revision_id:?string}> $results
 * @property CarbonImmutable $created_at
 */
final class MatchGameInstance extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['number' => 'integer', 'map' => 'encrypted', 'mode' => 'encrypted', 'game_patch' => 'encrypted',
            'reason' => 'encrypted', 'results' => 'encrypted:array', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
