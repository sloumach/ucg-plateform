<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\TeamEligibilityRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Domain\Models\TeamLineupEligibilityReport;
use App\Modules\Teams\Domain\Models\TeamLineupRuleBinding;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentTeamEligibilityRepository implements TeamEligibilityRepository
{
    public function rules(string $org, array $filters): LengthAwarePaginator
    {
        $query = TeamEligibilityRuleset::query()->where('organization_id', $org)->with('game:id,key,name');
        foreach (['game_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderByDesc('created_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function findRule(string $org, string $id, bool $lock = false): TeamEligibilityRuleset
    {
        return TeamEligibilityRuleset::query()->where('organization_id', $org)->with('game:id,key,name')->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function game(string $id): GameCatalogueEntry
    {
        return GameCatalogueEntry::query()->where('kind', 'game')->findOrFail(strtolower($id));
    }

    public function countRules(string $org): int
    {
        return TeamEligibilityRuleset::query()->where('organization_id', $org)->count();
    }

    public function saveRule(?TeamEligibilityRuleset $rule, array $values): TeamEligibilityRuleset
    {
        $rule ??= new TeamEligibilityRuleset;
        $rule->forceFill($values)->save();

        return $rule->load('game:id,key,name');
    }

    public function binding(string $org, string $lineupId): ?TeamLineupRuleBinding
    {
        return TeamLineupRuleBinding::query()->where('organization_id', $org)->where('lineup_id', $lineupId)->with('ruleset.game:id,key,name')->first();
    }

    public function bind(array $values): TeamLineupRuleBinding
    {
        $binding = new TeamLineupRuleBinding;
        $binding->forceFill($values)->save();

        return $binding->load('ruleset.game:id,key,name');
    }

    public function reports(string $org, string $lineupId, int $perPage, int $page): LengthAwarePaginator
    {
        return TeamLineupEligibilityReport::query()->where('organization_id', $org)->where('lineup_id', $lineupId)->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function countReports(string $org, string $lineupId): int
    {
        return TeamLineupEligibilityReport::query()->where('organization_id', $org)->where('lineup_id', $lineupId)->count();
    }

    public function report(array $values): TeamLineupEligibilityReport
    {
        $report = new TeamLineupEligibilityReport;
        $report->forceFill($values)->save();

        return $report;
    }
}
