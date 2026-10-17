<?php

namespace App\Modules\Scheduling\Application\Data;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class CalendarPage
{
    /** @param LengthAwarePaginator<int,CalendarActivity> $activities */
    public function __construct(public LengthAwarePaginator $activities, public string $viewerTimezone, public string $displayTimezone,
        public string $from, public string $to) {}
}
