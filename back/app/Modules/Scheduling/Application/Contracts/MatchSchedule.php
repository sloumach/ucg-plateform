<?php

namespace App\Modules\Scheduling\Application\Contracts;

use Carbon\CarbonImmutable;

interface MatchSchedule
{
    /** Normalize only; this does not create a calendar reservation.
     * @return array{starts_at:CarbonImmutable,ends_at:CarbonImmutable,origin_timezone:string}
     */
    public function period(string $start, string $end, string $timezone): array;
}
