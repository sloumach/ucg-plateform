<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Application\Validation\TeamInput;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TeamService
{
    public function __construct(private TeamRepository $teams, private EffectiveGameCatalogue $games, private TenantAuthorization $authorization,
        private TenantBusinessGuard $guard, private TenantAuditJournal $audit, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginate(TenantContext $context, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = GameCatalogueInput::validate($this->validator, $filters, TeamInput::pageRules());
        if (isset($values['game_id'])) {
            $values['game_id'] = strtolower($values['game_id']);
        }

        return $this->teams->paginate($context->organizationId, $values);
    }

    public function find(TenantContext $context, string $id): Team
    {
        $this->authorization->authorize($context, 'sports.read');

        return $this->teams->find($context->organizationId, $id);
    }

    /** @param array<string, mixed> $input */
    public function save(TenantContext $context, array $input, ?string $id = null, ?string $ipAddress = null): Team
    {
        $values = GameCatalogueInput::validate($this->validator, $input, TeamInput::saveRules($id === null));
        if ($id !== null && array_diff(array_keys($values), ['version']) === []) {
            throw ValidationException::withMessages(['team' => __('teams.empty')]);
        }
        $this->authorize($context);
        try {
            return $this->database->transaction(function () use ($context, $values, $id, $ipAddress): Team {
                $this->guard->lock($context);
                $this->authorize($context);
                $team = $id === null ? null : $this->teams->find($context->organizationId, $id, true);
                if ($team !== null && $team->version !== $values['version']) {
                    throw new DomainConflictException(__('teams.stale'), 'TEAM_VERSION_STALE');
                }
                if ($team?->status === 'archived') {
                    throw new DomainConflictException(__('teams.archived'), 'TEAM_ARCHIVED');
                }
                if ($team === null && $this->teams->count($context->organizationId) >= TeamInput::MAX_TEAMS) {
                    throw new DomainConflictException(__('teams.limit'), 'TEAM_LIMIT_REACHED');
                }
                $archivingOnly = $team !== null && ($values['status'] ?? null) === 'archived' && array_diff(array_keys($values), ['status', 'version']) === [];
                if (! $archivingOnly) {
                    $this->games->assertGameAvailable($context->organizationId, $team->game_id ?? strtolower($values['game_id']));
                }
                $attributes = $values;
                unset($attributes['version']);
                foreach (['name', 'season', 'custom_category', 'short_name'] as $field) {
                    if (isset($attributes[$field])) {
                        $attributes[$field] = trim($attributes[$field]);
                    }
                }
                $kind = $attributes['kind'] ?? $team?->kind;
                $category = array_key_exists('custom_category', $attributes) ? $attributes['custom_category'] : $team?->custom_category;
                if (($kind === 'custom') !== ($category !== null)) {
                    throw ValidationException::withMessages(['custom_category' => __('teams.category')]);
                }
                $before = $team === null ? [] : $this->state($team);
                $saved = $this->teams->save($team, [...($team === null ? ['organization_id' => $context->organizationId,
                    'game_id' => strtolower($values['game_id']), 'status' => 'draft', 'custom_category' => null, 'short_name' => null,
                    'public_description' => null, 'color' => null] : []), ...$attributes, 'version' => (string) Str::uuid()]);
                $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
                    AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress,
                    $before, [...$this->state($saved), 'team_changed_fields' => array_keys($attributes)]));

                return $saved;
            });
        } catch (UniqueConstraintViolationException) {
            throw new DomainConflictException(__('teams.duplicate'), 'TEAM_SLUG_ALREADY_EXISTS');
        }
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }

    /** @return array<string, mixed> */
    private function state(Team $team): array
    {
        return ['team_id' => $team->id, 'team_status' => $team->status, 'version' => $team->version];
    }
}
