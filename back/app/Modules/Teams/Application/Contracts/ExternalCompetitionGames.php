<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExternalCompetitionGames
{
    /**
     * @return LengthAwarePaginator<int,array{id:string,label:string}> */
    public function choices(TenantContext $context, int $page, int $perPage): LengthAwarePaginator;

    /**
     * @return array{id:string,key:string,name:string} */
    public function available(TenantContext $context, string $id): array;
}
