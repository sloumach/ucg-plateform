<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Carbon\CarbonImmutable;
use Database\Factories\ExternalOpponentFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $key
 * @property string $kind
 * @property string $name
 * @property string|null $short_name
 * @property string|null $organization_name
 * @property string|null $game_id
 * @property GameCatalogueEntry|null $game
 * @property string $source_label
 * @property string|null $source_url
 * @property CarbonImmutable $observed_at
 * @property string $status
 * @property string $version
 */
final class ExternalOpponent extends Model
{
    /** @use HasFactory<ExternalOpponentFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): ExternalOpponentFactory
    {
        return ExternalOpponentFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<GameCatalogueEntry, $this> */
    public function game(): BelongsTo
    {
        return $this->belongsTo(GameCatalogueEntry::class, 'game_id');
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('external_opponents.history'), 'EXTERNAL_OPPONENT_HISTORY_RETAINED'));
        self::updating(function (self $opponent): void {
            if ($opponent->isDirty(['id', 'organization_id', 'key', 'kind', 'game_id'])) {
                throw new DomainConflictException(__('external_opponents.immutable'), 'EXTERNAL_OPPONENT_IDENTITY_IMMUTABLE');
            }
            if ($opponent->getOriginal('status') === 'archived' && $opponent->isDirty()) {
                throw new DomainConflictException(__('external_opponents.archived'), 'EXTERNAL_OPPONENT_ARCHIVED');
            }
        });
    }
}
