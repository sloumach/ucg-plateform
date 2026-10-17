<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Models\CalendarFeed;

interface CalendarFeedRepository
{
    public function personal(string $organizationId, int $userId, bool $lock = false): ?CalendarFeed;

    public function find(string $organizationId, string $id, bool $lock = false): CalendarFeed;

    /** @param array<string,mixed> $attributes */
    public function save(?CalendarFeed $feed, array $attributes): CalendarFeed;
}
