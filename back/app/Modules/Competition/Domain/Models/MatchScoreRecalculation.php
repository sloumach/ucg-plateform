<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $correction_id
 * @property array<string,mixed> $series
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at
 */
final class MatchScoreRecalculation extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'series'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['series' => 'encrypted:array', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
