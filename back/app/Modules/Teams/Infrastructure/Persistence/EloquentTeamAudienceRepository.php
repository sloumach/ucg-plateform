<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\TeamAudienceRepository;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as Query;
use Illuminate\Validation\ValidationException;

final readonly class EloquentTeamAudienceRepository implements TeamAudienceRepository
{
    public function current(string $org, ?string $personId): array
    {
        if ($personId === null) {
            return ['teams' => [], 'games' => []];
        }
        $teams = $this->teams($org)->whereExists(function (Query $q) use ($org, $personId): void {
            $q->selectRaw('1')->from('team_memberships as period')->where('period.organization_id', $org)
                ->whereColumn('period.team_id', 'teams.id')->where('period.person_id', $personId)
                ->where('period.status', '!=', 'cancelled')->where('period.starts_at', '<=', now())
                ->where(fn (Query $p) => $p->whereNull('period.ends_at')->orWhere('period.ends_at', '>', now()));
        })->limit(1001)->get(['teams.id', 'teams.game_id']);
        if ($teams->count() > 1000) {
            throw ValidationException::withMessages(['audience' => __('communication.too_many_scopes')]);
        }

        return ['teams' => array_values($teams->map(fn (Team $team): string => $team->id)->all()), 'games' => array_values($teams->map(fn (Team $team): string => $team->game_id)->unique()->all())];
    }

    public function choices(string $org, string $type, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $type === 'team' ? $this->teams($org) : $this->games($org);
        $table = $type === 'team' ? 'teams' : 'game_catalogue_entries';

        return $query->orderBy($table.'.name')->orderBy($table.'.id')
            ->paginate($perPage, [$table.'.id', $table.'.name'], 'page', $page)
            ->through(fn (Team|GameCatalogueEntry $item): array => ['id' => $item->id, 'label' => $item->name]);
    }

    public function assertActive(string $org, string $type, string $id): void
    {
        ($type === 'team' ? $this->teams($org) : $this->games($org))->whereKey($id)->firstOrFail();
    }

    /** @return Builder<Team> */
    private function teams(string $org): Builder
    {
        return Team::query()->where('teams.organization_id', $org)->where('teams.status', 'active')
            ->whereIn('teams.game_id', $this->games($org)->select('game_catalogue_entries.id'));
    }

    /** @return Builder<GameCatalogueEntry> */
    private function games(string $org): Builder
    {
        return GameCatalogueEntry::query()->where('kind', 'game')->where('active', true)
            ->where(function (Builder $q) use ($org): void {
                $q->whereExists(fn (Query $s) => $s->selectRaw('1')->from('organization_games')
                    ->where('organization_id', $org)->whereColumn('game_id', 'game_catalogue_entries.id')->where('enabled', true))
                    ->orWhere(fn (Builder $default) => $default->where('default_enabled', true)
                        ->whereNotExists(fn (Query $s) => $s->selectRaw('1')->from('organization_games')
                            ->where('organization_id', $org)->whereColumn('game_id', 'game_catalogue_entries.id')));
            });
    }
}
