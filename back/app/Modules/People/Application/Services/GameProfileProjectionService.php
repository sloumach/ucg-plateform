<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\Tenancy\Application\Contracts\FieldVisibility;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantDataRecord;
use App\Modules\Tenancy\Application\Contracts\TenantDataVisibility;

final readonly class GameProfileProjectionService
{
    public function __construct(private TenantDataVisibility $visibility, private GameProfileFreshness $freshness) {}

    /** @param list<GameProfile> $profiles
     * @return list<array<string, mixed>>
     */
    public function profiles(TenantContext $context, array $profiles): array
    {
        $records = [];
        foreach ($profiles as $profile) {
            $records[] = new TenantDataRecord($profile->organization_id, [
                'id' => $profile->id, 'person_id' => $profile->person_id, 'game_key' => $profile->game_key,
                'platform_key' => $profile->platform_key, 'region_key' => $profile->region_key,
                'rank' => $profile->rank, 'game_role' => $profile->game_role,
                'observed_at' => $profile->observed_at?->toIso8601String(), 'freshness' => $this->freshness->status($profile->observed_at),
                'status' => $profile->status->value, 'version' => $profile->version,
                'external_accounts_count' => (int) $profile->external_accounts_count, 'data_source' => 'manual']);
        }

        return $this->visibility->projectMany($context, $records, $this->fields([
            'person_id', 'game_key', 'platform_key', 'region_key', 'rank', 'game_role',
            'observed_at', 'freshness', 'status', 'external_accounts_count', 'data_source']));
    }

    /** @param list<ExternalGameAccount> $accounts
     * @return list<array<string, mixed>>
     */
    public function accounts(TenantContext $context, array $accounts): array
    {
        $records = [];
        foreach ($accounts as $account) {
            $records[] = new TenantDataRecord($account->organization_id, [
                'id' => $account->id, 'game_profile_id' => $account->game_profile_id,
                'game_key' => $account->game_key, 'platform_key' => $account->platform_key, 'region_key' => $account->region_key,
                'provider_key' => $account->provider_key, 'external_id' => $account->external_id,
                'display_name' => $account->display_name, 'observed_at' => $account->observed_at?->toIso8601String(),
                'freshness' => $this->freshness->status($account->observed_at), 'status' => $account->status->value,
                'version' => $account->version, 'data_source' => 'manual', 'ownership_verified' => false]);
        }

        return $this->visibility->projectMany($context, $records, $this->fields([
            'game_profile_id', 'game_key', 'platform_key', 'region_key', 'provider_key',
            'external_id', 'display_name', 'observed_at', 'freshness', 'status', 'data_source', 'ownership_verified']));
    }

    /** @param list<string> $names
     * @return array<string, FieldVisibility>
     */
    private function fields(array $names): array
    {
        $fields = ['id' => new FieldVisibility('organization.view'), 'version' => new FieldVisibility('organization.view')];
        foreach ($names as $name) {
            $fields[$name] = new FieldVisibility('sports.read');
        }

        return $fields;
    }
}
