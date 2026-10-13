<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Database\Factories\GameCatalogueEntryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $kind
 * @property string $key
 * @property string $name
 * @property string|null $game_id
 * @property string $scope_key
 * @property bool $active
 * @property bool $default_enabled
 * @property array{platforms: array<string, list<string>>, regions: list<string>}|null $definition
 * @property string $version
 */
final class GameCatalogueEntry extends Model
{
    /** @use HasFactory<GameCatalogueEntryFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected static function newFactory(): GameCatalogueEntryFactory
    {
        return GameCatalogueEntryFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'default_enabled' => 'boolean', 'definition' => 'array'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('game_catalogue.history_retained'), 'GAME_CATALOGUE_HISTORY_RETAINED'));
        self::updating(function (self $entry): void {
            if ($entry->isDirty(['id', 'kind', 'key', 'game_id', 'scope_key', 'default_enabled'])) {
                throw new DomainConflictException(__('game_catalogue.immutable'), 'GAME_CATALOGUE_IDENTITY_IMMUTABLE');
            }
        });
    }
}
