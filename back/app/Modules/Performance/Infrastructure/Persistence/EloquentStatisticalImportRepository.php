<?php

namespace App\Modules\Performance\Infrastructure\Persistence;

use App\Modules\Performance\Domain\Contracts\StatisticalImportRepository;
use App\Modules\Performance\Domain\Models\StatisticalImport;
use App\Modules\Performance\Domain\Models\StatisticalImportExecution;
use App\Modules\Performance\Domain\Models\StatisticalImportReceipt;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentStatisticalImportRepository implements StatisticalImportRepository
{
    public function find(string $organization, int $actor, string $id, bool $lock = false): StatisticalImport
    {
        return StatisticalImport::query()->with('execution')->where('organization_id', $organization)->where('actor_user_id', $actor)->whereKey(strtolower($id))->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function createExecution(array $attributes): StatisticalImportExecution
    {
        $execution = new StatisticalImportExecution;
        $execution->forceFill($attributes)->save();

        return $execution;
    }

    public function saveExecution(StatisticalImportExecution $execution, array $attributes): void
    {
        $execution->forceFill($attributes)->save();
    }

    public function receipt(string $organization, string $key): ?StatisticalImportReceipt
    {
        return StatisticalImportReceipt::query()->where('organization_id', $organization)->where('identity_key', $key)->first();
    }

    public function createReceipt(array $attributes): void
    {
        (new StatisticalImportReceipt)->forceFill($attributes)->save();
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
        return StatisticalImport::query()->with('execution')->where('organization_id', $organization)->where('actor_user_id', $actor)
            ->orderByDesc('id')->paginate(10, ['id', 'organization_id', 'row_count', 'valid_count', 'invalid_count', 'created_at'], 'page', $page);
    }
}
