<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Database\Factories\TeamEligibilityRulesetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $game_id
 * @property string $name
 * @property string $version
 * @property string $status
 * @property int $min_starters
 * @property int $max_starters
 * @property int $max_substitutes
 * @property string|null $platform_key
 * @property list<string> $allowed_regions
 * @property list<string> $allowed_ranks
 * @property int|null $min_age
 * @property int|null $max_age
 * @property int $observation_max_days
 * @property CarbonImmutable|null $lock_at
 * @property int $created_by
 * @property GameCatalogueEntry $game
 */
final class TeamEligibilityRuleset extends Model
{
    /** @use HasFactory<TeamEligibilityRulesetFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected function casts(): array
    {
        return ['min_starters' => 'integer', 'max_starters' => 'integer', 'max_substitutes' => 'integer', 'min_age' => 'integer', 'max_age' => 'integer',
            'allowed_regions' => 'array', 'allowed_ranks' => 'array', 'observation_max_days' => 'integer', 'lock_at' => 'immutable_datetime'];
    }

    protected static function newFactory(): TeamEligibilityRulesetFactory
    {
        return TeamEligibilityRulesetFactory::new();
    }

    /** @return BelongsTo<GameCatalogueEntry,$this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(GameCatalogueEntry::class, 'game_id');
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('team_eligibility.immutable'), 'TEAM_RULESET_IMMUTABLE'));
        self::updating(function (self $rules): void {
            if ($rules->getOriginal('status') !== 'active' || $rules->status !== 'archived' || array_diff($rules->getDirty() ? array_keys($rules->getDirty()) : [], ['status', 'updated_at']) !== []) {
                throw new DomainConflictException(__('team_eligibility.immutable'), 'TEAM_RULESET_IMMUTABLE');
            }
        });
    }
}
