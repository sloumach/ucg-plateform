<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Validation\ExternalOpponentInput;
use App\Modules\Teams\Domain\Contracts\ExternalOpponentRepository;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
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

final readonly class ExternalOpponentService
{
    public function __construct(private ExternalOpponentRepository $opponents, private EffectiveGameCatalogue $games,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ExternalOpponent>
     */
    public function paginate(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = ExternalOpponentInput::validate($this->validator, $filters, ExternalOpponentInput::pageRules());
        if (isset($values['game_id'])) {
            $values['game_id'] = strtolower($values['game_id']);
        }

        return $this->opponents->paginate($context->organizationId, $values);
    }

    public function find(TenantContext $context, string $id): ExternalOpponent
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->opponents->find($context->organizationId, $id);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ipAddress = null): ExternalOpponent
    {
        $values = ExternalOpponentInput::validate($this->validator, $input, ExternalOpponentInput::saveRules($id === null));
        unset($values['confirm_public_data']);
        if ($id !== null && array_diff(array_keys($values), ['version']) === []) {
            throw ValidationException::withMessages(['opponent' => __('external_opponents.empty')]);
        }
        $this->authorize($context);
        try {
            return $this->database->transaction(function () use ($context, $values, $id, $ipAddress): ExternalOpponent {
                $this->guard->lock($context);
                $this->authorize($context);
                $opponent = $id === null ? null : $this->opponents->find($context->organizationId, $id, true);
                if ($opponent !== null && $opponent->version !== $values['version']) {
                    throw new DomainConflictException(__('external_opponents.stale'), 'EXTERNAL_OPPONENT_VERSION_STALE');
                }
                if ($opponent?->status === 'archived') {
                    throw new DomainConflictException(__('external_opponents.archived'), 'EXTERNAL_OPPONENT_ARCHIVED');
                }
                if ($opponent === null && $this->opponents->count($context->organizationId) >= ExternalOpponentInput::MAX_REFERENCES) {
                    throw new DomainConflictException(__('external_opponents.limit'), 'EXTERNAL_OPPONENT_LIMIT_REACHED');
                }
                $archivingOnly = $opponent !== null && ($values['status'] ?? null) === 'archived'
                    && array_diff(array_keys($values), ['status', 'version']) === [];
                $gameId = $opponent->game_id ?? ($values['game_id'] ?? null);
                if ($gameId !== null && ! $archivingOnly) {
                    $this->games->assertGameAvailable($context->organizationId, strtolower($gameId));
                }
                $attributes = $values;
                unset($attributes['version']);
                if (isset($attributes['game_id'])) {
                    $attributes['game_id'] = strtolower($attributes['game_id']);
                }
                foreach (['name', 'short_name', 'organization_name', 'source_label'] as $field) {
                    if (isset($attributes[$field])) {
                        $attributes[$field] = trim($attributes[$field]);
                    }
                }
                if (isset($attributes['observed_at'])) {
                    $attributes['observed_at'] = CarbonImmutable::parse($attributes['observed_at'])->utc();
                }
                $before = $opponent === null ? [] : $this->state($opponent);
                $saved = $this->opponents->save($opponent, [
                    ...($opponent === null ? ['organization_id' => $context->organizationId, 'game_id' => $gameId === null ? null : strtolower($gameId),
                        'short_name' => null, 'organization_name' => null, 'source_url' => null, 'status' => 'active'] : []),
                    ...$attributes, 'version' => (string) Str::uuid(),
                ]);
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                    AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress,
                    $before, [...$this->state($saved), 'opponent_changed_fields' => array_keys($attributes)]));

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('external_opponents.duplicate'), 'EXTERNAL_OPPONENT_KEY_ALREADY_EXISTS');
        }
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }

    /** @return array<string, mixed> */
    private function state(ExternalOpponent $opponent): array
    {
        return ['external_opponent_id' => $opponent->id, 'opponent_status' => $opponent->status, 'version' => $opponent->version];
    }
}
