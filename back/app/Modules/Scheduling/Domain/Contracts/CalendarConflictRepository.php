<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Models\CalendarActivity;
use App\Modules\Scheduling\Domain\Models\CalendarConflictOverride;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CalendarConflictRepository
{
    /** @param list<string> $people
     * @param  list<string>  $resources
     * @return list<array<string,mixed>>
     */
    public function detect(string $org, ?string $except, CarbonImmutable $from, CarbonImmutable $to, ?string $team, array $people, array $resources, bool $availability, int $limit): array;

    /** @param array<string,mixed> $snapshot */
    public function recordOverride(CalendarActivity $activity, int $actor, string $reason, array $snapshot, int $count): void;

    /** @return LengthAwarePaginator<int,CalendarConflictOverride> */
    public function history(string $org, string $activity, int $page, int $perPage): LengthAwarePaginator;
}
