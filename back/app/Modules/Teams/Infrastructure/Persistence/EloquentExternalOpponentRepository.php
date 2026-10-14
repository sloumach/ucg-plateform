<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\ExternalOpponentRepository;
use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\ExternalOpponentSnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentExternalOpponentRepository implements ExternalOpponentRepository
{
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = ExternalOpponent::query()->where('organization_id', strtolower($organizationId))->with('game:id,key,name,active');
        foreach (['kind', 'status', 'game_id'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['q'])) {
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q']));
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$prefix.'%']);
        }
        $order = ($filters['sort'] ?? 'name') === 'newest' ? 'created_at' : 'name';

        return $query->orderBy($order, $order === 'created_at' ? 'desc' : 'asc')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function find(string $organizationId, string $id, bool $lock = false): ExternalOpponent
    {
        $query = ExternalOpponent::query()->where('organization_id', strtolower($organizationId))->with('game:id,key,name,active');

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function count(string $organizationId): int
    {
        return ExternalOpponent::query()->where('organization_id', strtolower($organizationId))->count();
    }

    public function save(?ExternalOpponent $opponent, array $attributes): ExternalOpponent
    {
        $opponent ??= new ExternalOpponent;
        $opponent->forceFill($attributes)->save();

        return $opponent->load('game:id,key,name,active');
    }

    public function snapshots(string $organizationId, string $opponentId, array $filters): LengthAwarePaginator
    {
        return ExternalOpponentSnapshot::query()->where('organization_id', strtolower($organizationId))->where('opponent_id', strtolower($opponentId))
            ->orderByDesc('captured_at')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
    }

    public function snapshotCount(string $organizationId, string $opponentId): int
    {
        return ExternalOpponentSnapshot::query()->where('organization_id', strtolower($organizationId))->where('opponent_id', strtolower($opponentId))->count();
    }

    public function findSnapshot(string $organizationId, string $snapshotId): ExternalOpponentSnapshot
    {
        return ExternalOpponentSnapshot::query()->where('organization_id', strtolower($organizationId))->findOrFail(strtolower($snapshotId));
    }

    public function capture(array $attributes): ExternalOpponentSnapshot
    {
        $snapshot = new ExternalOpponentSnapshot;
        $snapshot->forceFill($attributes)->save();

        return $snapshot;
    }
}
