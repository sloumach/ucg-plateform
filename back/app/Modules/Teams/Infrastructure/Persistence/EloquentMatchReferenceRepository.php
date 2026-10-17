<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\MatchReferenceRepository;
use App\Modules\Teams\Domain\Models\ExternalOpponentSnapshot;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

final readonly class EloquentMatchReferenceRepository implements MatchReferenceRepository
{
    public function choices(string $org, string $type, ?string $gameId, int $page, int $perPage): LengthAwarePaginator
    {
        if ($type === 'team') {
            return Team::query()->where('organization_id', $org)->where('status', 'active')
                ->when($gameId, fn ($q) => $q->where('game_id', $gameId))->orderBy('name')->orderBy('id')
                ->paginate($perPage, ['id', 'name'], 'page', $page)->through(fn (Team $t): array => ['id' => $t->id, 'label' => $t->name]);
        }

        $query = ExternalOpponentSnapshot::query()->where('organization_id', $org);
        if ($gameId !== null) {
            $game = GameCatalogueEntry::query()->where('kind', 'game')->findOrFail($gameId);
            $query->where(fn (Builder $q) => $q->whereNull('game_key')->orWhere('game_key', $game->key));
        }

        return $query->orderByDesc('captured_at')->orderByDesc('id')
            ->paginate($perPage, ['id', 'name', 'context_label'], 'page', $page)
            ->through(fn (ExternalOpponentSnapshot $s): array => ['id' => $s->id, 'label' => $s->name.' · '.$s->context_label]);
    }

    public function snapshots(string $org, string $type, array $ids, string $gameId, string $gameKey): array
    {
        if ($ids === []) {
            return [];
        }
        $result = [];
        if ($type === 'team') {
            $records = Team::query()->where('organization_id', $org)->where('status', 'active')->where('game_id', $gameId)
                ->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'name', 'version']);
            foreach ($records as $record) {
                $result[$record->id] = ['label' => $record->name, 'version' => $record->version];
            }
        } else {
            $records = ExternalOpponentSnapshot::query()->where('organization_id', $org)->whereIn('id', $ids)->get();
            foreach ($records as $snapshot) {
                if ($snapshot->game_key !== null && $snapshot->game_key !== $gameKey) {
                    throw ValidationException::withMessages(['participants' => __('matches.game_mismatch')]);
                }
                $result[$snapshot->id] = ['label' => $snapshot->name, 'version' => $snapshot->opponent_version];
            }
        }
        if (count($result) !== count($ids)) {
            throw (new ModelNotFoundException)->setModel($type === 'team' ? Team::class : ExternalOpponentSnapshot::class);
        }

        return $result;
    }
}
