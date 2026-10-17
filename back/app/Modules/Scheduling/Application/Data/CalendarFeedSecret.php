<?php

namespace App\Modules\Scheduling\Application\Data;

use App\Modules\Scheduling\Domain\Models\CalendarFeed;

final readonly class CalendarFeedSecret
{
    public function __construct(public CalendarFeed $feed, public string $url) {}
}
