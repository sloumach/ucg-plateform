<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\People\Application\Data\ExternalGameAccountData;
use App\Modules\People\Application\Validation\GameProfileInput;
use App\Modules\People\Domain\Contracts\ExternalGameAccountRepository;
use App\Modules\People\Domain\Contracts\GameProfileRepository;
use App\Modules\People\Domain\GameProfileStatus;
use App\Modules\People\Domain\Models\ExternalGameAccount;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class ExternalGameAccountService
{
    public function __construct(private ExternalGameAccountRepository $accounts, private GameProfileRepository $profiles,
        private GameProfileCatalogue $catalogue, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private ConnectionInterface $database,
        private Factory $validator, private GameProfileFreshness $freshness) {}

    /** @return LengthAwarePaginator<int, ExternalGameAccount> */
    public function accounts(TenantContext $context, string $personId, string $profileId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->validator->make(['per_page' => $perPage, 'page' => $page], GameProfileInput::pageRules(), GameProfileInput::messages())->validate();
        $this->profiles->find($context->organizationId, $personId, $profileId);

        return $this->accounts->paginate($context->organizationId, $profileId, $perPage, $page);
    }

    public function find(TenantContext $context, string $personId, string $profileId, string $id): ExternalGameAccount
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->profiles->find($context->organizationId, $personId, $profileId);

        return $this->accounts->find($context->organizationId, $profileId, $id);
    }

    public function save(TenantContext $context, string $personId, string $profileId, ExternalGameAccountData $data,
        ?string $id = null, ?string $ipAddress = null): ExternalGameAccount
    {
        $attributes = GameProfileInput::validate($this->validator, $data->attributes, GameProfileInput::accountRules($id === null), $id === null, $data->version);
        $this->authorization->authorize($context, 'sports.write');
        $this->freshness->assertConfigured();
        try {
            return $this->database->transaction(function () use ($context, $personId, $profileId, $data, $attributes, $id, $ipAddress): ExternalGameAccount {
                $this->guard->lock($context);
                $this->authorization->authorize($context, 'sports.write');
                $profile = $this->profiles->find($context->organizationId, $personId, $profileId, true);
                $account = $id === null ? null : $this->accounts->find($context->organizationId, $profileId, $id, true);
                if ($account !== null && $account->version !== $data->version) {
                    throw new DomainConflictException(__('game_profiles.account_stale'), 'EXTERNAL_ACCOUNT_VERSION_STALE');
                }
                if ($account !== null && $account->external_id !== null && array_key_exists('external_id', $attributes)
                    && $attributes['external_id'] !== $account->external_id) {
                    throw new DomainConflictException(__('game_profiles.immutable'), 'EXTERNAL_ACCOUNT_IDENTITY_IMMUTABLE');
                }
                $archivingOnly = $account !== null && ($attributes['status'] ?? null) === 'archived'
                    && array_diff(array_keys($attributes), ['status']) === [];
                if ($profile->status !== GameProfileStatus::Active && ! $archivingOnly) {
                    throw new DomainConflictException(__('game_profiles.profile_archived'), 'GAME_PROFILE_ARCHIVED');
                }
                if (! $archivingOnly) {
                    $providerKey = $account === null ? $attributes['provider_key'] : $account->provider_key;
                    $this->catalogue->assertAvailable($context->organizationId, $profile->game_key, $profile->platform_key,
                        $profile->region_key, $providerKey);
                }
                if ($account === null && $this->accounts->countForProfile($context->organizationId, $profileId) >= GameProfileInput::MAX_ACCOUNTS_PER_PROFILE) {
                    throw new DomainConflictException(__('game_profiles.account_limit'), 'EXTERNAL_ACCOUNT_LIMIT_REACHED');
                }
                $values = $account === null ? ['external_id' => null, 'display_name' => null, 'observed_at' => null, 'status' => 'active',
                    ...$attributes, 'organization_id' => $context->organizationId, 'game_profile_id' => $profile->id,
                    'game_key' => $profile->game_key, 'platform_key' => $profile->platform_key, 'region_key' => $profile->region_key] : $attributes;
                $externalId = array_key_exists('external_id', $values) ? $values['external_id'] : $account?->external_id;
                $displayName = array_key_exists('display_name', $values) ? $values['display_name'] : $account?->display_name;
                if ($externalId === null && $displayName === null) {
                    throw ValidationException::withMessages(['external_id' => __('game_profiles.validation.account_identity')]);
                }
                if (array_intersect(array_keys($attributes), ['external_id', 'display_name']) !== [] && ! array_key_exists('observed_at', $attributes)) {
                    $values['observed_at'] = null;
                }
                if (isset($values['observed_at'])) {
                    $values['observed_at'] = CarbonImmutable::parse($values['observed_at'])->utc();
                }
                $before = $account === null ? [] : $this->state($account);
                $saved = $this->accounts->save($account, [...$values, 'version' => (string) Str::uuid()]);
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId,
                    AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain,
                    $context->requestId, $ipAddress, $before, [...$this->state($saved), 'person_id' => $profile->person_id,
                        'external_account_changed_fields' => array_keys($attributes)]));

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('game_profiles.account_duplicate'), 'EXTERNAL_ACCOUNT_ALREADY_LINKED');
        }
    }

    /** @return array<string, mixed> */
    private function state(ExternalGameAccount $account): array
    {
        return ['game_profile_id' => $account->game_profile_id, 'external_account_id' => $account->id,
            'external_id_available' => $account->external_id !== null, 'status' => $account->status->value,
            'version' => $account->version, 'observed_at' => $account->observed_at?->toIso8601String()];
    }
}
