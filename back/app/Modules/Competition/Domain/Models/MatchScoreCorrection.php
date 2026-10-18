<?php

namespace App\Modules\Competition\Domain\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** @phpstan-type CorrectedGame array{game_id:string,number:int,outcome:string,results:list<array{participant_id:string,label:string,score:?int,lineup_revision_id:?string}>}
 * @property string $id
 * @property string $organization_id
 * @property string $match_id
 * @property string $base_match_version
 * @property string $match_version
 * @property ?string $base_correction_id
 * @property string $idempotency_key
 * @property string $fingerprint
 * @property int $number
 * @property list<CorrectedGame> $before
 * @property list<CorrectedGame> $after
 * @property list<string> $changed_ids
 * @property string $reason
 * @property int $actor_user_id
 * @property CarbonImmutable $created_at
 * @property ?MatchScoreRecalculation $recalculation
 */
final class MatchScoreCorrection extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'before', 'after', 'changed_ids', 'reason', 'fingerprint', 'idempotency_key'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['before' => 'encrypted:array', 'after' => 'encrypted:array', 'changed_ids' => 'encrypted:array', 'reason' => 'encrypted', 'number' => 'integer', 'actor_user_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return HasOne<MatchScoreRecalculation,$this> */
    public function recalculation(): HasOne
    {
        return $this->hasOne(MatchScoreRecalculation::class, 'correction_id');
    }
}
