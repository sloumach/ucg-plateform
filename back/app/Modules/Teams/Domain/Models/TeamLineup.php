<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Database\Factories\TeamLineupFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $team_id
 * @property string $game_id
 * @property string $purpose
 * @property string $label
 * @property CarbonImmutable $effective_at
 * @property string $status
 * @property string $version
 * @property string $team_name
 * @property string $game_key
 * @property string $game_name
 * @property string $season
 * @property int $created_by
 * @property int|null $finalized_by
 * @property CarbonImmutable|null $finalized_at
 * @property Collection<int, TeamLineupEntry> $entries
 * @property TeamLineupRuleBinding|null $ruleBinding
 * @property TeamLineupEligibilityReport|null $finalEligibilityReport
 */
final class TeamLineup extends Model
{
    /** @use HasFactory<TeamLineupFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['effective_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): TeamLineupFactory
    {
        return TeamLineupFactory::new();
    }

    /** @return HasMany<TeamLineupEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(TeamLineupEntry::class, 'lineup_id')->orderBy('position');
    }

    /** @return HasOne<TeamLineupRuleBinding, $this> */
    public function ruleBinding(): HasOne
    {
        return $this->hasOne(TeamLineupRuleBinding::class, 'lineup_id');
    }

    /** @return HasOne<TeamLineupEligibilityReport, $this> */
    public function finalEligibilityReport(): HasOne
    {
        return $this->hasOne(TeamLineupEligibilityReport::class, 'lineup_id')->where('stage', 'final');
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('team_lineups.immutable'), 'TEAM_LINEUP_IMMUTABLE'));
        self::updating(function (self $lineup): void {
            if ($lineup->getOriginal('status') !== 'draft' || $lineup->isDirty(['id', 'organization_id', 'team_id', 'game_id', 'purpose', 'created_by', 'created_at'])) {
                throw new DomainConflictException(__('team_lineups.immutable'), 'TEAM_LINEUP_IMMUTABLE');
            }
        });
    }
}
