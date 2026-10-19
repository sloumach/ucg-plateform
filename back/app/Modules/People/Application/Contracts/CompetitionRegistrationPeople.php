<?php

namespace App\Modules\People\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompetitionRegistrationPeople
{
    /**
     * @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, int $page, int $size): LengthAwarePaginator;

    /**
     * @param list<string> $ids
     * @return array<string,array{label:string,version:string}> */
    public function snapshots(TenantContext $context, array $ids): array;

    /** Internal correlation only; callers recheck current tenant authorization. */
    /**
     * @param list<string> $ids
     * @return array<string,int> */
    public function accounts(string $org, array $ids): array;
}
