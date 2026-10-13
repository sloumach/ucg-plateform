<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\TeamLineupRepository;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentTeamLineupRepository implements TeamLineupRepository
{
    /** @return Builder<TeamLineup> */
    private function query(string $org, string $teamId): Builder
    {
        return TeamLineup::query()->where('organization_id', strtolower($org))->where('team_id', strtolower($teamId));
    }

    public function paginate(string $org, string $teamId, array $filters): LengthAwarePaginator
    {
        $query = $this->query($org, $teamId)->with(['entries', 'ruleBinding.ruleset', 'finalEligibilityReport']);
        foreach (['purpose', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }

        return $query->orderByDesc('effective_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $org, string $teamId, string $id, bool $lock = false): TeamLineup
    {
        $query = $this->query($org, $teamId)->with(['entries', 'ruleBinding.ruleset', 'finalEligibilityReport']);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function count(string $org, string $teamId): int
    {
        return $this->query($org, $teamId)->count();
    }

    public function memberships(string $org, string $teamId, array $ids): array
    {
        return TeamMembership::query()->where('organization_id', $org)->where('team_id', $teamId)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id')->all();
    }

    public function save(?TeamLineup $lineup, array $values): TeamLineup
    {
        $lineup ??= new TeamLineup;
        $lineup->forceFill($values)->save();

        return $lineup;
    }

    public function replaceEntries(TeamLineup $lineup, array $entries): void
    {
        $lineup->entries()->delete();
        foreach ($entries as $entry) {
            $lineup->entries()->make()->forceFill(['organization_id' => $lineup->organization_id, 'lineup_id' => $lineup->id, ...$entry])->save();
        }
        $lineup->unsetRelation('entries');
    }
}
