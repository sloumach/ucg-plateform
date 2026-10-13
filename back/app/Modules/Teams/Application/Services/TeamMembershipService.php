<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Application\Validation\TeamMembershipInput as Input;
use App\Modules\Teams\Domain\Contracts\TeamMembershipRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Teams\Domain\Models\TeamMovement;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TeamMembershipService
{
    public function __construct(private TeamMembershipRepository $records, private TeamRepository $teams, private SportingPeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private EffectiveGameCatalogue $games,
        private TeamMembershipJournal $journal, private Factory $validator, private ConnectionInterface $database) {}

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamMembership>
     */
    public function paginate(TenantContext $context, string $teamId, array $filters): LengthAwarePaginator
    {
        $teamId = strtolower($teamId);
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $filters, Input::readRules());
        if (isset($values['person_id'])) {
            $values['person_id'] = strtolower($values['person_id']);
        }
        $this->teams->find($context->organizationId, $teamId);

        return $this->records->paginate($context->organizationId, $teamId, $values);
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamMovement>
     */
    public function movements(TenantContext $context, string $teamId, array $filters): LengthAwarePaginator
    {
        $teamId = strtolower($teamId);
        $this->authorization->authorize($context, 'sports.read');
        $values = GameCatalogueInput::validate($this->validator, $filters, GameCatalogueInput::pageRules(false));
        $this->teams->find($context->organizationId, $teamId);

        return $this->records->movements($context->organizationId, $teamId, (int) ($values['per_page'] ?? 10), (int) ($values['page'] ?? 1));
    }

    /** @param array<string,mixed> $input */
    public function create(TenantContext $context, string $teamId, array $input): TeamMembership
    {
        $teamId = strtolower($teamId);
        $values = Input::validate($this->validator, $input, Input::createRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $teamId, $values): TeamMembership {
            $this->guard->lock($context);
            $this->authorize($context);
            $team = $this->teams->find($context->organizationId, $teamId, true);
            if ($team->status === 'archived') {
                throw new DomainConflictException(__('teams.archived'), 'TEAM_ARCHIVED');
            }
            $this->people->assertAssignable($context, strtolower($values['person_id']));
            $this->games->assertGameAvailable($context->organizationId, $team->game_id);
            $start = CarbonImmutable::parse($values['starts_at'])->utc();
            $end = isset($values['ends_at']) ? CarbonImmutable::parse($values['ends_at'])->utc() : null;
            $this->assertPeriod($start, $end);
            $this->assertCapacity($context, $values['person_id']);
            $this->assertOverlap($context, $teamId, $values['person_id'], $values['role_key'], $start, $end);
            $saved = $this->records->save(null, ['organization_id' => $context->organizationId, 'team_id' => $teamId, 'person_id' => strtolower($values['person_id']),
                'role_key' => $values['role_key'], 'starts_at' => $start, 'ends_at' => $end, 'status' => 'active', 'version' => (string) Str::uuid()]);
            $this->journal->record($context, $saved, 'created', $values['reason_key'], $start);

            return $saved;
        });
    }

    /** @param array<string,mixed> $input */
    public function change(TenantContext $context, string $teamId, string $id, array $input): TeamMembership
    {
        $teamId = strtolower($teamId);
        $values = Input::validate($this->validator, $input, Input::actionRules());
        $moving = in_array($values['action'], ['transfer', 'promotion'], true);
        if (! $moving && array_intersect(array_keys($values), ['destination_team_id', 'destination_version', 'role_key']) !== []) {
            throw ValidationException::withMessages(['action' => __('team_memberships.action')]);
        }
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $teamId, $id, $values, $moving): TeamMembership {
            $this->guard->lock($context);
            $this->authorize($context);
            $sourceTeam = $this->teams->find($context->organizationId, $teamId, true);
            $membership = $this->records->find($context->organizationId, $teamId, $id);
            if ($membership->version !== $values['version']) {
                throw new DomainConflictException(__('team_memberships.stale'), 'TEAM_MEMBERSHIP_VERSION_STALE');
            }
            $now = CarbonImmutable::now()->startOfSecond();
            $at = isset($values['effective_at']) ? CarbonImmutable::parse($values['effective_at'])->utc() : $now;
            if ($membership->status !== 'active' || ($membership->ends_at !== null && $membership->ends_at->lessThanOrEqualTo($now))) {
                throw new DomainConflictException(__('team_memberships.terminal'), 'TEAM_MEMBERSHIP_IMMUTABLE');
            }
            if ($moving) {
                if ($at->lessThan($now) || ! $membership->effectiveAt($at)) {
                    throw ValidationException::withMessages(['effective_at' => __('team_memberships.move_date')]);
                }
                $destination = $this->teams->find($context->organizationId, strtolower($values['destination_team_id']), true);
                if ($destination->id === $teamId || $destination->version !== $values['destination_version'] || $sourceTeam->status === 'archived' || $destination->status === 'archived'
                    || $sourceTeam->game_id !== $destination->game_id || ($values['action'] === 'promotion' && ($sourceTeam->kind !== 'academy' || $destination->kind !== 'first'))) {
                    throw new DomainConflictException(__('team_memberships.destination'), 'TEAM_MOVEMENT_DESTINATION_INVALID');
                }
                $this->people->assertAssignable($context, $membership->person_id);
                $this->games->assertGameAvailable($context->organizationId, $destination->game_id);
                $this->assertCapacity($context, $membership->person_id);
                $this->assertOverlap($context, $destination->id, $membership->person_id, $values['role_key'], $at, null);
                $target = $this->records->save(null, ['organization_id' => $context->organizationId, 'team_id' => $destination->id, 'person_id' => $membership->person_id,
                    'role_key' => $values['role_key'], 'starts_at' => $at, 'ends_at' => null, 'status' => 'active', 'version' => (string) Str::uuid()]);
                // Close all functions in the source team, including pending ones, in this same movement.
                foreach ($this->records->openForPerson($context->organizationId, $membership->person_id, $at) as $period) {
                    if ($period->team_id === $teamId) {
                        $this->finish($context, $period, $at, $values['reason_key'], $values['action'], $target);
                    }
                }

                return $target;
            }
            if ($values['action'] === 'cancel') {
                if ($membership->starts_at->lessThan($now) || isset($values['effective_at'])) {
                    throw ValidationException::withMessages(['effective_at' => __('team_memberships.cancel_date')]);
                }
            } else {
                $this->assertPeriod($membership->starts_at, $at, 'effective_at');
                if ($membership->ends_at !== null && $at->greaterThan($membership->ends_at)) {
                    throw ValidationException::withMessages(['effective_at' => __('team_memberships.shorten')]);
                }
            }

            return $this->finish($context, $membership, $at, $values['reason_key'], $values['action']);
        });
    }

    public function finish(TenantContext $context, TeamMembership $period, CarbonImmutable $at, string $reason, string $action, ?TeamMembership $target = null): TeamMembership
    {
        if ($this->database->transactionLevel() < 1) {
            throw new \LogicException('Team periods close only under the tenant transaction.');
        }
        if ($period->organization_id !== $context->organizationId) {
            throw new ModelNotFoundException;
        }
        $before = $this->journal->state($period);
        $cancel = $period->starts_at->greaterThanOrEqualTo($at);
        $saved = $this->records->save($period, ['status' => $cancel ? 'cancelled' : ($at->greaterThan(CarbonImmutable::now()) ? 'active' : 'closed'),
            'ends_at' => $cancel ? $period->ends_at : $at, 'version' => (string) Str::uuid()]);
        $this->journal->record($context, $saved, $action, $reason, $at, $before, $target);

        return $saved;
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }

    private function assertCapacity(TenantContext $context, string $personId): void
    {
        if ($this->records->count($context->organizationId, strtolower($personId)) >= Input::MAX_MEMBERSHIPS) {
            throw new DomainConflictException(__('team_memberships.limit'), 'TEAM_MEMBERSHIP_LIMIT_REACHED');
        }
    }

    private function assertPeriod(CarbonImmutable $start, ?CarbonImmutable $end, string $field = 'ends_at'): void
    {
        if ($end !== null && ! $end->greaterThan($start)) {
            throw ValidationException::withMessages([$field => __('team_memberships.period')]);
        }
    }

    private function assertOverlap(TenantContext $context, string $teamId, string $personId, string $role, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        if ($this->records->overlaps($context->organizationId, $teamId, strtolower($personId), $role, $start, $end)) {
            throw new DomainConflictException(__('team_memberships.overlap'), 'TEAM_MEMBERSHIP_OVERLAP');
        }
    }
}
