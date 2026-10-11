<?php

namespace App\Modules\People\Domain\Models;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Domain\GameProfileStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ExternalGameAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $game_profile_id
 * @property string $game_key
 * @property string $platform_key
 * @property string $region_key
 * @property string $provider_key
 * @property string|null $external_id
 * @property string|null $display_name
 * @property CarbonImmutable|null $observed_at
 * @property GameProfileStatus $status
 * @property string $version
 */
final class ExternalGameAccount extends Model
{
    /** @use HasFactory<ExternalGameAccountFactory> */
    use HasFactory, HasUuids;

    protected $table = 'person_external_accounts';

    protected $guarded = ['*'];

    protected $hidden = ['organization_id', 'external_id'];

    protected static function newFactory(): ExternalGameAccountFactory
    {
        return ExternalGameAccountFactory::new();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => GameProfileStatus::class, 'observed_at' => 'immutable_datetime'];
    }

    /** @param Builder<ExternalGameAccount> $query
     * @return Builder<ExternalGameAccount>
     */
    #[Scope]
    protected function forProfile(Builder $query, string $organizationId, string $profileId): Builder
    {
        return $query->where('organization_id', strtolower($organizationId))->where('game_profile_id', strtolower($profileId));
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new DomainConflictException(__('game_profiles.history_retained'), 'EXTERNAL_ACCOUNT_HISTORY_RETAINED'));
        self::updating(function (self $account): void {
            if ($account->isDirty(['id', 'organization_id', 'game_profile_id', 'game_key', 'platform_key', 'region_key', 'provider_key'])
                || ($account->getRawOriginal('external_id') !== null && $account->isDirty('external_id'))) {
                throw new DomainConflictException(__('game_profiles.immutable'), 'EXTERNAL_ACCOUNT_IDENTITY_IMMUTABLE');
            }
        });
    }
}
