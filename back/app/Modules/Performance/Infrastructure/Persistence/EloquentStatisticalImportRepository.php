<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentStatisticalImportRepository implements StatisticalImportRepository
{
    public function find(string $organization, int $actor, string $id): StatisticalImport
    {
        return StatisticalImport::query()->where('organization_id', $organization)->where('actor_user_id', $actor)->whereKey(strtolower($id))->firstOrFail();
    }

    public function fingerprint(string $organization, int $actor, string $fingerprint): ?StatisticalImport
    {
        return StatisticalImport::query()->where('organization_id', $organization)->where('actor_user_id', $actor)->where('fingerprint', $fingerprint)->first();
    }

    public function create(array $attributes): StatisticalImport
    {
        $run = new StatisticalImport;
        $run->forceFill($attributes)->save();

        return $run;
    }

    public function paginate(string $organization, int $actor, int $page): LengthAwarePaginator
    {
        return StatisticalImport::query()->where('organization_id', $organization)->where('actor_user_id', $actor)
            ->orderByDesc('id')->paginate(10, ['id', 'row_count', 'valid_count', 'invalid_count', 'created_at'], 'page', $page);
    }
}
