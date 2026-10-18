<?php

namespace App\Modules\Teams\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\People\Application\Contracts\EligibilityPeopleDirectory;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Contracts\MatchLineups;
use App\Modules\Teams\Domain\Contracts\TeamLineupRepository;
use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Eligibility\EligibilityEvaluator;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Pagination\Paginator;

final readonly class MatchLineupDirectoryService implements MatchLineups
{
    public function __construct(private TeamLineupRepository $lineups, private TeamRepository $teams,
        private TeamLineupService $history, private SportingPeopleDirectory $people, private EligibilityPeopleDirectory $facts,
        private EligibilityEvaluator $evaluator, private TenantAuthorization $authorization, private ConnectionInterface $database,
        private EffectiveGameCatalogue $games) {}

    public function choices(TenantContext $context, string $teamId, int $page, int $perPage): LengthAwarePaginator
    {
        $this->authorize($context);
        $this->teams->find($context->organizationId, $teamId);

        $source = $this->lineups->paginate($context->organizationId, $teamId, ['status' => 'finalized', 'purpose' => 'match',
            'page' => max(1, min(100000, $page)), 'per_page' => max(1, min(100, $perPage))]);
        $items = [];
        foreach ($source->items() as $lineup) {
            $items[] = ['id' => $lineup->id, 'label' => $lineup->label];
        }

        return new \Illuminate\Pagination\LengthAwarePaginator($items, $source->total(), $source->perPage(), $source->currentPage(),
            ['path' => Paginator::resolveCurrentPath()]);
    }

    public function snapshot(TenantContext $context, string $teamId, string $lineupId, string $gameId): array
    {
        $this->authorize($context);
        $lineup = $this->lineups->find($context->organizationId, $teamId, $lineupId, true);
        if ($lineup->game_id !== $gameId || $lineup->purpose !== 'match') {
            throw new DomainConflictException(__('match_lineups.source'), 'MATCH_LINEUP_SOURCE_INVALID');
        }

        return [...$this->history->snapshot($context, $teamId, $lineupId), 'version' => $lineup->version];
    }

    public function assess(TenantContext $context, array $snapshot, CarbonImmutable $at): array
    {
        $this->authorize($context);
        if ($this->database->transactionLevel() < 1) {
            throw new \LogicException('Match eligibility requires the tenant transaction.');
        }
        $team = $this->teams->find($context->organizationId, $snapshot['team_id'], true);
        $this->games->assertGameAvailable($context->organizationId, $team->game_id);
        $lineup = $this->lineups->find($context->organizationId, $team->id, $snapshot['id'], true);
        if ($team->status === 'archived' || $lineup->status !== 'finalized' || $lineup->version !== $snapshot['version'] || $team->game_id !== $snapshot['game_id']) {
            throw new DomainConflictException(__('match_lineups.source'), 'MATCH_LINEUP_SOURCE_INVALID');
        }
        $ids = array_values($lineup->entries->map(fn ($e): string => $e->membership_id)->all());
        $periods = $this->lineups->memberships($context->organizationId, $team->id, $ids);
        $this->people->snapshots($context, array_values($lineup->entries->map(fn ($e): string => $e->person_id)->all()));
        $roster = [];
        $roles = array_column($snapshot['participants'], 'slot_key', 'person_id');
        foreach ($lineup->entries as $entry) {
            $period = $periods[$entry->membership_id] ?? null;
            $roster[$entry->person_id] = $period !== null && $period->version === $entry->membership_version
                && $period->effectiveAt($at) && in_array($period->role_key, ['player', 'substitute'], true);
            $entry->slot_key = $roles[$entry->person_id] ?? throw new \LogicException('The match pool must retain every finalized lineup entry.');
        }
        // Evaluate a transient projection at the match instant, keeping the original deadline proof.
        $lineup->effective_at = $at;
        $binding = $lineup->ruleBinding;
        if ($binding === null) {
            $result = ['status' => 'unassessed', 'checks' => array_map(fn (string $id, bool $valid): array => [
                'code' => 'roster', 'status' => $valid ? 'passed' : 'failed', 'person_id' => $id], array_keys($roster), array_values($roster))];
        } else {
            $rule = $binding->ruleset;
            $facts = $this->facts->facts($context, array_keys($roster), $rule->game->key, $rule->platform_key, $at, $rule->min_age, $rule->max_age);
            $result = $this->evaluator->evaluate($rule, $lineup, $facts, $roster, CarbonImmutable::now()->startOfSecond());
        }
        if (in_array(false, $roster, true) || in_array($result['status'], ['ineligible', 'incomplete'], true)) {
            throw new DomainConflictException(__('match_lineups.eligibility'), 'MATCH_LINEUP_NOT_ELIGIBLE');
        }

        return [...$result, 'evaluated_at' => CarbonImmutable::now()->toIso8601String(), 'effective_at' => $at->toIso8601String()];
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'sports.read');
        $this->authorization->authorize($context, 'sports.write');
    }
}
