<?php

namespace App\Modules\Scheduling\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademySchedule
{
    /** @return LengthAwarePaginator<int, AcademyActivity> */
    public function choices(TenantContext $context, string $from, string $to, string $timezone, int $page, int $perPage): LengthAwarePaginator;

    /** Current shared Academy activities only; absent keys never disclose inaccessible records.
     * @param list<string> $ids
     * @return array<string, AcademyActivity> */
    public function available(TenantContext $context, array $ids, bool $lock = false): array;
}
