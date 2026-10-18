<?php

namespace App\Modules\Competition\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $format
 * @property int $game_limit
 * @property bool $allow_draw
 * @property string $match_version
 */
final class MatchScoreRules extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'match_score_rules';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'actor_user_id'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['game_limit' => 'integer', 'allow_draw' => 'boolean', 'reason' => 'encrypted'];
    }
}
