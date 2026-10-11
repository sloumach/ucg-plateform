<?php

namespace App\Modules\People\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\GameProfileStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GameProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $person_id
 * @property string $game_key
 * @property string $platform_key
 * @property string $region_key
 * @property string|null $rank
 * @property string|null $game_role
 * @property CarbonImmutable|null $observed_at
 * @property GameProfileStatus $status
 * @property string $version
 * @property int $external_accounts_count
 */
final class GameProfile extends Model
{
    /** @use HasFactory<GameProfileFactory> */
    use HasFactory, HasUuids;

    protected $table = 'person_game_profiles';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id'];

    protected static function newFactory(): GameProfileFactory
    {
        return GameProfileFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => GameProfileStatus::class, 'observed_at' => 'immutable_datetime'];
    }

    /** @return HasMany<ExternalGameAccount, $this> */
    public function externalAccounts(): HasMany
    {
        // UUIDs are globally unique; the composite SQL FK enforces the complete tenant/game context.
        return $this->hasMany(ExternalGameAccount::class, 'game_profile_id');
    }

    /** @param Builder<GameProfile> $query
     * @return Builder<GameProfile>
     */
    #[Scope]
    protected function forPerson(Builder $query, string $organizationId, string $personId): Builder
    {
        return $query->where('organization_id', strtolower($organizationId))->where('person_id', strtolower($personId));
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('game_profiles.history_retained'), 'GAME_PROFILE_HISTORY_RETAINED'));
        self::updating(function (self $profile): void {
            if ($profile->isDirty(['id', 'organization_id', 'person_id', 'game_key', 'platform_key', 'region_key'])) {
                throw new DomainConflictException(__('game_profiles.immutable'), 'GAME_PROFILE_IDENTITY_IMMUTABLE');
            }
        });
    }
}
