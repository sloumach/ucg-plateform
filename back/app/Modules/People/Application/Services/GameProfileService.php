<?php

namespace App\Modules\People\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\GameProfileCatalogue;
use App\Modules\People\Application\Data\GameProfileData;
use App\Modules\People\Application\Validation\GameProfileInput;
use App\Modules\People\Domain\Contracts\GameProfileRepository;
use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\GameProfile;
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

final readonly class GameProfileService
{
    public function __construct(private GameProfileRepository $profiles, private PersonRepository $people,
        private GameProfileCatalogue $catalogue, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private ConnectionInterface $database,
        private Factory $validator, private GameProfileFreshness $freshness) {}

    /** @return LengthAwarePaginator<int, GameProfile> */
    public function profiles(TenantContext $context, string $personId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->validator->make(['per_page' => $perPage, 'page' => $page], GameProfileInput::pageRules(), GameProfileInput::messages())->validate();
        $this->people->find($context->organizationId, $personId);

        return $this->profiles->paginate($context->organizationId, $personId, $perPage, $page);
    }

    public function find(TenantContext $context, string $personId, string $id): GameProfile
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->profiles->find($context->organizationId, $personId, $id);
    }

    public function save(TenantContext $context, string $personId, GameProfileData $data, ?string $id = null, ?string $ipAddress = null): GameProfile
    {
        $attributes = GameProfileInput::validate($this->validator, $data->attributes, GameProfileInput::profileRules($id === null), $id === null, $data->version);
        $this->authorization->authorize($context, 'sports.write');
        $this->freshness->assertConfigured();
        try {
            return $this->database->transaction(function () use ($context, $personId, $data, $attributes, $id, $ipAddress): GameProfile {
                $this->guard->lock($context);
                $this->authorization->authorize($context, 'sports.write');
                $this->people->find($context->organizationId, $personId, true);
                $profile = $id === null ? null : $this->profiles->find($context->organizationId, $personId, $id, true);
                if ($profile !== null && $profile->version !== $data->version) {
                    throw new DomainConflictException(__('game_profiles.profile_stale'), 'GAME_PROFILE_VERSION_STALE');
                }
                if ($profile === null && $this->profiles->countForPerson($context->organizationId, $personId) >= GameProfileInput::MAX_PROFILES_PER_PERSON) {
                    throw new DomainConflictException(__('game_profiles.profile_limit'), 'GAME_PROFILE_LIMIT_REACHED');
                }
                $values = $attributes;
                if ($profile !== null) {
                    $archivingOnly = ($attributes['status'] ?? null) === 'archived' && array_diff(array_keys($attributes), ['status']) === [];
                    if (! $archivingOnly) {
                        $this->catalogue->assertAvailable($context->organizationId, $profile->game_key, $profile->platform_key, $profile->region_key);
                    }
                } else {
                    $this->catalogue->assertAvailable($context->organizationId, $attributes['game_key'], $attributes['platform_key'], $attributes['region_key']);
                    $values = ['rank' => null, 'game_role' => null, 'observed_at' => null, 'status' => 'active',
                        ...$values, 'organization_id' => $context->organizationId, 'person_id' => strtolower($personId)];
                }
                if (array_intersect(array_keys($attributes), ['rank', 'game_role']) !== [] && ! array_key_exists('observed_at', $attributes)) {
                    $values['observed_at'] = null;
                }
                if (isset($values['observed_at'])) {
                    $values['observed_at'] = CarbonImmutable::parse($values['observed_at'])->utc();
                }
                $before = $profile === null ? [] : $this->state($profile);
                $saved = $this->profiles->save($profile, [...$values, 'version' => (string) Str::uuid()]);
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId,
                    AuditAction::DomainChanged, AuditTarget::Organization, $context->organizationId, AuditReason::Domain,
                    $context->requestId, $ipAddress, $before, [...$this->state($saved), 'game_profile_changed_fields' => array_keys($attributes)]));

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('game_profiles.profile_duplicate'), 'GAME_PROFILE_ALREADY_EXISTS');
        }
    }

    /** @return array<string, mixed> */
    private function state(GameProfile $profile): array
    {
        return ['person_id' => $profile->person_id, 'game_profile_id' => $profile->id,
            'status' => $profile->status->value, 'version' => $profile->version,
            'observed_at' => $profile->observed_at?->toIso8601String()];
    }
}
