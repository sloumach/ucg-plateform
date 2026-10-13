<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\EligibilityPeopleDirectory;
use App\Modules\Teams\Application\Validation\GameCatalogueInput;
use App\Modules\Teams\Application\Validation\TeamEligibilityInput as Input;
use App\Modules\Teams\Domain\Contracts\TeamEligibilityRepository;
use App\Modules\Teams\Domain\Contracts\TeamLineupRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Eligibility\EligibilityEvaluator;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamLineupEligibilityReport;
use App\Modules\Teams\Domain\Models\TeamLineupRuleBinding;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

final readonly class LineupEligibilityService
{
    public function __construct(private TeamEligibilityRepository $records, private TeamLineupRepository $lineups, private EligibilityPeopleDirectory $people,
        private EligibilityEvaluator $evaluator, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TeamEligibilityJournal $journal, private Factory $validator, private ConnectionInterface $database,
        private TeamRepository $teams, private EffectiveGameCatalogue $games) {}

    /** Internal operation under the same transaction as the draft creation. */
    public function bindCreated(TenantContext $context, TeamLineup $lineup, string $rulesetId, string $rulesetVersion): TeamLineupRuleBinding
    {
        if ($this->database->transactionLevel() < 1 || $lineup->organization_id !== $context->organizationId) {
            throw new \LogicException('Eligibility binding requires the tenant transaction.');
        }
        $this->authorize($context);
        if ($lineup->status !== 'draft' || $this->teams->find($context->organizationId, $lineup->team_id, true)->status === 'archived') {
            throw new DomainConflictException(__('team_eligibility.unavailable'), 'TEAM_RULESET_UNAVAILABLE');
        }
        $this->games->assertGameAvailable($context->organizationId, $lineup->game_id);
        if ($this->records->binding($context->organizationId, $lineup->id) !== null) {
            throw new DomainConflictException(__('team_eligibility.binding_retained'), 'TEAM_LINEUP_RULE_BINDING_RETAINED');
        }
        $rule = $this->records->findRule($context->organizationId, $rulesetId, true);
        if ($rule->version !== strtolower($rulesetVersion)) {
            throw new DomainConflictException(__('team_eligibility.stale'), 'TEAM_RULESET_VERSION_STALE');
        }
        if ($rule->status !== 'active' || $rule->game_id !== $lineup->game_id) {
            throw new DomainConflictException(__('team_eligibility.unavailable'), 'TEAM_RULESET_UNAVAILABLE');
        }
        if ($rule->lock_at !== null && ! CarbonImmutable::now()->lessThan($rule->lock_at)) {
            throw new DomainConflictException(__('team_eligibility.locked'), 'TEAM_LINEUP_LOCKED');
        }
        $binding = $this->records->bind(['organization_id' => $context->organizationId, 'lineup_id' => $lineup->id, 'ruleset_id' => $rule->id,
            'ruleset_version' => $rule->version, 'actor_user_id' => $context->actorUserId]);
        $this->journal->record($context, $rule->id, 'bound', 'active', $lineup->id);
        $lineup->unsetRelation('ruleBinding');

        return $binding;
    }

    /** @param array<string,mixed> $input */
    public function bind(TenantContext $context, string $teamId, string $id, array $input): TeamLineup
    {
        $values = Input::validate($this->validator, $input, Input::bindingRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $teamId, $id, $values): TeamLineup {
            $this->guard->lock($context);
            $this->authorize($context);
            $lineup = $this->lineups->find($context->organizationId, $teamId, $id, true);
            $this->draft($lineup, $values['version']);
            $this->bindCreated($context, $lineup, $values['ruleset_id'], $values['ruleset_version']);

            return $this->lineups->save($lineup, ['version' => (string) Str::uuid()])->load(['entries', 'ruleBinding.ruleset', 'finalEligibilityReport']);
        });
    }

    public function assertEditable(TenantContext $context, TeamLineup $lineup): void
    {
        $binding = $this->records->binding($context->organizationId, $lineup->id);
        if ($binding === null) {
            return;
        }
        if ($binding->ruleset->lock_at !== null && ! CarbonImmutable::now()->lessThan($binding->ruleset->lock_at)) {
            throw new DomainConflictException(__('team_eligibility.locked'), 'TEAM_LINEUP_LOCKED');
        }
        if ($binding->ruleset->status !== 'active') {
            throw new DomainConflictException(__('team_eligibility.unavailable'), 'TEAM_RULESET_UNAVAILABLE');
        }
    }

    public function finalProof(TenantContext $context, TeamLineup $lineup, string $nextVersion): void
    {
        $binding = $this->records->binding($context->organizationId, $lineup->id);
        if ($binding === null) {
            return;
        }
        $this->assertEditable($context, $lineup);
        $result = $this->evaluate($context, $lineup, $binding);
        if ($result['status'] !== 'eligible') {
            throw new DomainConflictException(__($result['status'] === 'incomplete' ? 'team_eligibility.incomplete' : 'team_eligibility.ineligible'),
                $result['status'] === 'incomplete' ? 'TEAM_LINEUP_ELIGIBILITY_INCOMPLETE' : 'TEAM_LINEUP_INELIGIBLE');
        }
        $this->record($context, $lineup, $binding, $result, 'final', $nextVersion);
    }

    /** @param array<string,mixed> $input */
    public function preview(TenantContext $context, string $teamId, string $id, array $input): TeamLineupEligibilityReport
    {
        $values = Input::validate($this->validator, $input, Input::evaluateRules());
        $this->authorize($context);

        return $this->database->transaction(function () use ($context, $teamId, $id, $values): TeamLineupEligibilityReport {
            $this->guard->lock($context);
            $this->authorize($context);
            $lineup = $this->lineups->find($context->organizationId, $teamId, $id, true);
            $this->draft($lineup, $values['version']);
            $binding = $this->records->binding($context->organizationId, $lineup->id) ?? throw new DomainConflictException(__('team_eligibility.not_bound'), 'TEAM_LINEUP_RULESET_REQUIRED');

            return $this->record($context, $lineup, $binding, $this->evaluate($context, $lineup, $binding), 'preview', $lineup->version);
        });
    }

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamLineupEligibilityReport>
     */
    public function reports(TenantContext $context, string $teamId, string $id, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'sports.read');
        $values = GameCatalogueInput::validate($this->validator, $filters, GameCatalogueInput::pageRules(false));
        $this->lineups->find($context->organizationId, $teamId, $id);

        return $this->records->reports($context->organizationId, strtolower($id), (int) ($values['per_page'] ?? 10), (int) ($values['page'] ?? 1));
    }

    /** @return array{status:string,checks:list<array{code:string,status:string,person_id:string|null}>} */
    private function evaluate(TenantContext $context, TeamLineup $lineup, TeamLineupRuleBinding $binding): array
    {
        $lineup->loadMissing('entries');
        $ids = array_values($lineup->entries->map(fn ($entry): string => $entry->membership_id)->all());
        $periods = $this->lineups->memberships($context->organizationId, $lineup->team_id, $ids);
        $roster = [];
        foreach ($lineup->entries as $entry) {
            $period = $periods[$entry->membership_id] ?? null;
            $roster[$entry->person_id] = $period !== null && $period->person_id === $entry->person_id && $period->version === $entry->membership_version && $period->effectiveAt($lineup->effective_at)
                && in_array($period->role_key, ['player', 'substitute'], true);
        }
        $rule = $binding->ruleset;
        $facts = $this->people->facts($context, array_values($lineup->entries->map(fn ($entry): string => $entry->person_id)->all()), $rule->game->key, $rule->platform_key, $lineup->effective_at, $rule->min_age, $rule->max_age);

        return $this->evaluator->evaluate($rule, $lineup, $facts, $roster, CarbonImmutable::now()->startOfSecond());
    }

    /** @param array{status:string,checks:list<array{code:string,status:string,person_id:string|null}>} $result */
    private function record(TenantContext $context, TeamLineup $lineup, TeamLineupRuleBinding $binding, array $result, string $stage, string $version): TeamLineupEligibilityReport
    {
        $cap = Input::MAX_REPORTS - ($stage === 'preview' ? 1 : 0);
        if ($this->records->countReports($context->organizationId, $lineup->id) >= $cap) {
            throw new DomainConflictException(__('team_eligibility.limit'), 'TEAM_ELIGIBILITY_LIMIT');
        }
        $report = $this->records->report(['organization_id' => $context->organizationId, 'lineup_id' => $lineup->id, 'binding_id' => $binding->id,
            'ruleset_id' => $binding->ruleset_id, 'ruleset_version' => $binding->ruleset_version, 'lineup_version' => $version, 'stage' => $stage, ...$result,
            'evaluated_at' => CarbonImmutable::now()->startOfSecond(), 'actor_user_id' => $context->actorUserId]);
        $this->journal->record($context, $report->id, $stage, $report->status, $lineup->id);

        return $report;
    }

    private function draft(TeamLineup $lineup, string $version): void
    {
        if ($lineup->version !== strtolower($version)) {
            throw new DomainConflictException(__('team_eligibility.stale'), 'TEAM_LINEUP_VERSION_STALE');
        }
        if ($lineup->status !== 'draft') {
            throw new DomainConflictException(__('team_lineups.immutable'), 'TEAM_LINEUP_IMMUTABLE');
        }
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'teams.manage');
    }
}
