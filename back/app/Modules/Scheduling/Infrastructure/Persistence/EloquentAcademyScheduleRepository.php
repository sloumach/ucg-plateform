<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\AcademyScheduleRepository;
use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentAcademyScheduleRepository implements AcademyScheduleRepository
{
    public function choices(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->shared($organizationId)->where('starts_at', '>=', $from)->where('starts_at', '<', $to)
            ->orderBy('starts_at')->orderBy('id')->paginate($perPage, ['id', 'version', 'title', 'starts_at', 'ends_at', 'origin_timezone'], 'page', $page);
    }

    public function available(string $organizationId, array $ids, bool $lock): array
    {
        return array_values($this->shared($organizationId)->whereIn('id', $ids)->orderBy('id')->when($lock, fn ($query) => $query->lockForUpdate())
            ->get(['id', 'version', 'title', 'starts_at', 'ends_at', 'origin_timezone'])->all());
    }

    /** @return Builder<CalendarActivity> */
    private function shared(string $organizationId): Builder
    {
        return CalendarActivity::query()->where('organization_id', strtolower($organizationId))->where('visibility', 'organization')
            ->where('kind', 'academy')->whereNull('archived_at')->whereNull('cancelled_at');
    }
}
