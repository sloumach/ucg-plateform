<?php

namespace App\Modules\Scheduling\Infrastructure\Persistence;

use App\Modules\Scheduling\Domain\Contracts\CalendarFeedRepository;
use App\Modules\Scheduling\Domain\Models\CalendarFeed;

final readonly class EloquentCalendarFeedRepository implements CalendarFeedRepository
{
    public function personal(string $organizationId, int $userId, bool $lock = false): ?CalendarFeed
    {
        return CalendarFeed::query()->where('organization_id', $organizationId)->where('user_id', $userId)->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    public function find(string $organizationId, string $id, bool $lock = false): CalendarFeed
    {
        return CalendarFeed::query()->where('organization_id', $organizationId)->when($lock, fn ($q) => $q->lockForUpdate())->findOrFail(strtolower($id));
    }

    public function save(?CalendarFeed $feed, array $attributes): CalendarFeed
    {
        $feed ??= new CalendarFeed;
        $feed->forceFill($attributes)->save();

        return $feed;
    }
}
