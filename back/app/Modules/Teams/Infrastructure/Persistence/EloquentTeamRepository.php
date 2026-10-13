<?php

namespace App\Modules\Teams\Infrastructure\Persistence;

use App\Modules\Teams\Domain\Contracts\TeamRepository;
use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentTeamRepository implements TeamRepository
{
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator
    {
        $query = Team::query()->where('organization_id', strtolower($organizationId))->with('game:id,key,name,active');
        foreach (['kind', 'status', 'game_id', 'season'] as $field) {
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

    public function find(string $organizationId, string $id, bool $lock = false): Team
    {
        $query = Team::query()->where('organization_id', strtolower($organizationId))->with('game:id,key,name,active');

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function count(string $organizationId): int
    {
        return Team::query()->where('organization_id', strtolower($organizationId))->count();
    }

    public function save(?Team $team, array $attributes): Team
    {
        $team ??= new Team;
        $team->forceFill($attributes)->save();

        return $team->load('game:id,key,name,active');
    }
}
