<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AcademyPeopleDirectory
{
    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /** Resolve an active tenant person; lock only inside the business transaction. */
    public function account(TenantContext $context, string $personId, bool $lock = false): ?int;

    /** @param list<string> $personIds
     * @return array<string, string> */
    public function labels(TenantContext $context, array $personIds): array;
}
