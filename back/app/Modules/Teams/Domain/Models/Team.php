<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $game_id
 * @property string $slug
 * @property string $season
 * @property string $name
 * @property string $kind
 * @property string|null $custom_category
 * @property string|null $short_name
 * @property string|null $public_description
 * @property string|null $color
 * @property string $status
 * @property string $version
 * @property GameCatalogueEntry $game
 */
final class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): TeamFactory
    {
        return TeamFactory::new();
    }

    /** @return BelongsTo<GameCatalogueEntry, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(GameCatalogueEntry::class, 'game_id');
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('teams.history'), 'TEAM_HISTORY_RETAINED'));
        self::updating(function (self $team): void {
            if ($team->isDirty(['id', 'organization_id', 'game_id', 'slug', 'season'])) {
                throw new DomainConflictException(__('teams.immutable'), 'TEAM_IDENTITY_IMMUTABLE');
            }
            if ($team->getOriginal('status') === 'archived' && $team->isDirty()) {
                throw new DomainConflictException(__('teams.archived'), 'TEAM_ARCHIVED');
            }
        });
    }
}
