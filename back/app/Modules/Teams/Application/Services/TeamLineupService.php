<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Contracts\HistoricalLineups;
use App\Modules\Teams\Application\Validation\TeamLineupInput as Input;
use App\Modules\Teams\Domain\Contracts\TeamLineupRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Domain\Models\TeamLineup;
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

final readonly class TeamLineupService implements HistoricalLineups
{
    public function __construct(private TeamLineupRepository $records, private TeamRepository $teams, private SportingPeopleDirectory $people,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private EffectiveGameCatalogue $games,
        private TeamLineupJournal $journal, private Factory $validator, private ConnectionInterface $database, private LineupEligibilityService $eligibility) {}

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamLineup>
     */
    public function paginate(TenantContext $context, string $teamId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = Input::validate($this->validator, $filters, Input::readRules());
        $this->teams->find($context->organizationId, $teamId);

        return $this->records->paginate($context->organizationId, $teamId, $values);
    }

    public function show(TenantContext $context, string $teamId, string $id): TeamLineup
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->teams->find($context->organizationId, $teamId);

        return $this->records->find($context->organizationId, $teamId, $id);
    }

    /** @param array<string,mixed> $input */
    public function create(TenantContext $context, string $teamId, array $input): TeamLineup
    {
        $values = Input::validate($this->validator, $input, Input::createRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $teamId, $values): TeamLineup {
            $this->guard->lock($context);
            $this->authorize($context);
            $team = $this->editableTeam($context, $teamId, $values['team_version']);
            if ($this->records->count($context->organizationId, $team->id) >= Input::MAX_LINEUPS) {
                throw new DomainConflictException(__('team_lineups.limit'), 'TEAM_LINEUP_LIMIT_REACHED');
            }
            $at = CarbonImmutable::parse($values['effective_at'])->utc();
            $entries = $this->entries($context, $team->id, $at, $values['entries']);
            $lineup = $this->records->save(null, ['organization_id' => $context->organizationId, 'team_id' => $team->id, 'game_id' => $team->game_id,
                'purpose' => $values['purpose'], 'label' => $values['label'], 'effective_at' => $at, 'status' => 'draft',
                'version' => (string) Str::uuid(), 'created_by' => $context->actorUserId, ...$this->teamSnapshot($team)]);
            $this->records->replaceEntries($lineup, $entries);
            $lineup->load('entries');
            if (isset($values['ruleset_id'], $values['ruleset_version'])) {
                $this->eligibility->bindCreated($context, $lineup, $values['ruleset_id'], $values['ruleset_version']);
            }
            $lineup->load(['ruleBinding.ruleset', 'finalEligibilityReport']);
            $this->journal->record($context, $lineup, 'created');

            return $lineup;
        });
    }

    /** @param array<string,mixed> $input */
    public function change(TenantContext $context, string $teamId, string $id, array $input): TeamLineup
    {
        $values = Input::validate($this->validator, $input, Input::actionRules(is_string($input['action'] ?? null) ? $input['action'] : ''));
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $teamId, $id, $values): TeamLineup {
            $this->guard->lock($context);
            $this->authorize($context);
            $team = $this->teams->find($context->organizationId, $teamId, true);
            $lineup = $this->records->find($context->organizationId, $team->id, $id, true);
            if ($lineup->version !== strtolower($values['version'])) {
                throw new DomainConflictException(__('team_lineups.stale'), 'TEAM_LINEUP_VERSION_STALE');
            }
            if ($lineup->status !== 'draft') {
                throw new DomainConflictException(__('team_lineups.immutable'), 'TEAM_LINEUP_IMMUTABLE');
            }
            $before = $this->journal->state($lineup);
            $attributes = ['version' => (string) Str::uuid()];
            if ($values['action'] === 'cancel') {
                $attributes['status'] = 'cancelled';
            } else {
                $this->eligibility->assertEditable($context, $lineup);
                $team = $this->editableTeam($context, $team->id, $values['team_version']);
                $at = $values['action'] === 'replace' ? CarbonImmutable::parse($values['effective_at'])->utc() : $lineup->effective_at;
                $selected = $values['action'] === 'replace' ? $values['entries'] : $lineup->entries->map(fn ($entry): array => [
                    'membership_id' => $entry->membership_id, 'membership_version' => $entry->membership_version, 'slot_key' => $entry->slot_key])->all();
                $entries = $this->entries($context, $team->id, $at, $selected);
                $this->records->replaceEntries($lineup, $entries);
                $attributes = [...$attributes, ...$this->teamSnapshot($team)];
                if ($values['action'] === 'replace') {
                    $attributes = [...$attributes, 'label' => $values['label'], 'effective_at' => $at];
                } else {
                    $this->eligibility->finalProof($context, $lineup, $attributes['version']);
                    $attributes = [...$attributes, 'status' => 'finalized', 'finalized_at' => CarbonImmutable::now()->startOfSecond(), 'finalized_by' => $context->actorUserId];
                }
            }
            $lineup = $this->records->save($lineup, $attributes)->load(['entries', 'ruleBinding.ruleset', 'finalEligibilityReport']);
            $this->journal->record($context, $lineup, $values['action'], $before);

            return $lineup;
        });
    }

    public function snapshot(TenantContext $context, string $teamId, string $lineupId): array
    {
        $lineup = $this->show($context, $teamId, $lineupId);
        if ($lineup->status !== 'finalized') {
            throw new DomainConflictException(__('team_lineups.not_finalized'), 'TEAM_LINEUP_NOT_FINALIZED');
        }

        return ['id' => $lineup->id, 'team_id' => $lineup->team_id, 'game_id' => $lineup->game_id, 'purpose' => $lineup->purpose,
            'label' => $lineup->label, 'effective_at' => $lineup->effective_at->toIso8601String(), 'finalized_at' => $lineup->finalized_at?->toIso8601String(),
            'team_name' => $lineup->team_name, 'game_name' => $lineup->game_name, 'game_key' => $lineup->game_key, 'season' => $lineup->season,
            'ruleset_id' => $lineup->ruleBinding?->ruleset_id, 'ruleset_version' => $lineup->ruleBinding?->ruleset_version,
            'eligibility_report_id' => $lineup->finalEligibilityReport?->id, 'eligibility_status' => $lineup->finalEligibilityReport->status ?? 'unassessed',
            'participants' => $lineup->entries->map(fn ($entry): array => ['person_id' => $entry->person_id, 'pseudonym' => $entry->pseudonym,
                'membership_id' => $entry->membership_id, 'membership_role_key' => $entry->membership_role_key, 'slot_key' => $entry->slot_key, 'position' => $entry->position])->all()];
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }

    private function editableTeam(TenantContext $context, string $id, string $version): Team
    {
        $team = $this->teams->find($context->organizationId, $id, true);
        if ($team->status === 'archived') {
            throw new DomainConflictException(__('teams.archived'), 'TEAM_ARCHIVED');
        }
        if ($team->version !== strtolower($version)) {
            throw new DomainConflictException(__('team_lineups.stale'), 'TEAM_LINEUP_TEAM_STALE');
        }
        $this->games->assertGameAvailable($context->organizationId, $team->game_id);

        return $team;
    }

    /** @return array<string,string> */
    private function teamSnapshot(Team $team): array
    {
        return ['team_name' => $team->name, 'game_key' => $team->game->key, 'game_name' => $team->game->name, 'season' => $team->season];
    }

    /** @param list<array<string,string>> $selected
     * @return list<array<string,mixed>>
     */
    private function entries(TenantContext $context, string $teamId, CarbonImmutable $at, array $selected): array
    {
        if (! in_array('starter', array_column($selected, 'slot_key'), true)) {
            throw ValidationException::withMessages(['entries' => __('team_lineups.starter')]);
        }
        $ids = array_map(fn (array $entry): string => strtolower($entry['membership_id']), $selected);
        $periods = $this->records->memberships($context->organizationId, $teamId, $ids);
        if (count($periods) !== count($selected)) {
            throw new ModelNotFoundException;
        }
        $personIds = array_map(fn ($period): string => $period->person_id, array_values($periods));
        if (count(array_unique($personIds)) !== count($selected)) {
            throw ValidationException::withMessages(['entries' => __('team_lineups.duplicate')]);
        }
        $labels = $this->people->pseudonyms($context, $personIds);
        $entries = [];
        foreach ($selected as $position => $choice) {
            $period = $periods[strtolower($choice['membership_id'])];
            if ($period->version !== strtolower($choice['membership_version'])) {
                throw new DomainConflictException(__('team_lineups.stale'), 'TEAM_LINEUP_MEMBERSHIP_STALE');
            }
            if (! $period->effectiveAt($at) || ! in_array($period->role_key, ['player', 'substitute'], true)) {
                throw ValidationException::withMessages(['entries' => __('team_lineups.unavailable')]);
            }
            $entries[] = ['membership_id' => $period->id, 'membership_version' => $period->version, 'person_id' => $period->person_id,
                'pseudonym' => $labels[$period->person_id] ?? throw new ModelNotFoundException,
                'membership_role_key' => $period->role_key, 'slot_key' => $choice['slot_key'], 'position' => $position + 1];
        }

        return $entries;
    }
}
