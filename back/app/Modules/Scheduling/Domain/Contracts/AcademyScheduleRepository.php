<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademyScheduleRepository
{
    /** @return LengthAwarePaginator<int, CalendarActivity> */
    public function choices(string $organizationId, CarbonImmutable $from, CarbonImmutable $to, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return list<CalendarActivity> */
    public function available(string $organizationId, array $ids, bool $lock): array;
}
