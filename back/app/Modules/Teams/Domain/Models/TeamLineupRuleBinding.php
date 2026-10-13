<?php

namespace App\Modules\Teams\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $lineup_id
 * @property string $ruleset_id
 * @property string $ruleset_version
 * @property TeamEligibilityRuleset $ruleset
 */
final class TeamLineupRuleBinding extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    public const UPDATED_AT = null;

    /** @return BelongsTo<TeamEligibilityRuleset,$this> */
    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(TeamEligibilityRuleset::class, 'ruleset_id');
    }
}
