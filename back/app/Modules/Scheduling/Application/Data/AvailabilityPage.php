<?php

namespace App\Modules\Scheduling\Application\Data;

use App\Modules\Scheduling\Domain\Models\Availability;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class AvailabilityPage
{
    /** @param LengthAwarePaginator<int,Availability> $records */
    public function __construct(public LengthAwarePaginator $records, public ?string $personId, public string $viewerTimezone,
        public string $displayTimezone, public string $from, public string $to, public bool $canSelf, public bool $canManage) {}
}
