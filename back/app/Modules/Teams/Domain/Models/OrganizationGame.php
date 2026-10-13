<?php

namespace App\Modules\Teams\Domain\Models;

use App\Exceptions\DomainConflictException;
use Database\Factories\OrganizationGameFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $game_id
 * @property bool $enabled
 * @property array<string, list<string>> $restrictions
 * @property string $version
 */
final class OrganizationGame extends Model
{
    /** @use HasFactory<OrganizationGameFactory> */
    use HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): OrganizationGameFactory
    {
        return OrganizationGameFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'restrictions' => 'array'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('game_catalogue.history_retained'), 'GAME_CATALOGUE_HISTORY_RETAINED'));
        self::updating(function (self $setting): void {
            if ($setting->isDirty(['id', 'organization_id', 'game_id'])) {
                throw new DomainConflictException(__('game_catalogue.immutable'), 'GAME_CATALOGUE_IDENTITY_IMMUTABLE');
            }
        });
    }
}
