<?php

namespace App\Modules\Reporting\Infrastructure\Persistence;

use App\Modules\Reporting\Domain\Contracts\ReportExportRepository;
use App\Modules\Reporting\Domain\Models\ReportExport;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class EloquentReportExportRepository implements ReportExportRepository
{
    public function find(string $org, int $actor, string $id, bool $lock = false): ReportExport
    {
        return ReportExport::query()->where('organization_id', $org)->where('actor_user_id', $actor)->whereKey($id)
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public function byKey(string $org, int $actor, string $key): ?ReportExport
    {
        return ReportExport::query()->where('organization_id', $org)->where('actor_user_id', $actor)->where('request_key', $key)->first();
    }

    public function activeCount(string $org): int
    {
        return ReportExport::query()->where('organization_id', $org)->where('expires_at', '>', now())->count();
    }

    public function save(?ReportExport $row, array $attributes): ReportExport
    {
        $row ??= new ReportExport;
        $row->forceFill($attributes)->save();

        return $row;
    }

    public function paginate(string $org, int $actor, int $page, array $allowed): LengthAwarePaginator
    {
        return ReportExport::query()->where('organization_id', $org)->where('actor_user_id', $actor)
            ->where(function ($query) use ($allowed): void {
                $query->whereRaw('1=0');
                foreach ($allowed as $pair) {
                    $query->orWhere(fn ($q) => $q->where('card', $pair['card'])->where('view', $pair['view']));
                }
            })->orderByDesc('created_at')->orderByDesc('id')->paginate(10, ['*'], 'page', $page);
    }
}
