<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\CalendarResourceRepository;
use App\Modules\Scheduling\Domain\Models\CalendarResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class EloquentCalendarResourceRepository implements CalendarResourceRepository
{
    public function paginate(string $org, int $page, int $perPage, bool $archived): LengthAwarePaginator
    {
        return CalendarResource::query()->where('organization_id', $org)->where('status', $archived ? 'archived' : 'active')->orderBy('key')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $org, string $id, bool $lock = false): CalendarResource
    {
        $query = CalendarResource::query()->where('organization_id', $org);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail(strtolower($id));
    }

    public function save(?CalendarResource $record, array $attributes): CalendarResource
    {
        $record ??= new CalendarResource;
        $record->forceFill($attributes)->save();

        return $record;
    }

    public function assertAssignable(string $org, array $ids): void
    {
        $found = CalendarResource::query()->where('organization_id', $org)->where('status', 'active')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id'])->all();
        if (count($ids) !== count($found)) {
            throw new ModelNotFoundException;
        }
    }
}
