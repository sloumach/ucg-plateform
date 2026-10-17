<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\CalendarAudience;
use App\Modules\Scheduling\Domain\Models\CalendarChange;
use App\Modules\Scheduling\Domain\Models\CalendarChangeRecipient;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CalendarChangeRepository
{
    /** @param array<string,mixed> $attributes
     * @param  list<string>  $people
     * @param  array<string,int>  $accounts
     */
    public function append(array $attributes, array $people, array $accounts): CalendarChange;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,CalendarChange>
     */
    public function history(string $org, string $activityId, array $filters): LengthAwarePaginator;

    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,CalendarChangeRecipient>
     */
    public function inbox(string $org, CalendarAudience $audience, array $filters): LengthAwarePaginator;

    /** @return list<CalendarChangeRecipient> */
    public function sweep(string $org, int $limit): array;

    public function find(string $org, string $id, bool $lock = false): CalendarChangeRecipient;

    /** @param array<string,mixed> $attributes */
    public function update(CalendarChangeRecipient $notice, array $attributes): void;
}
