<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Closure;

interface TenantDepartureAccess
{
    /** @template TResult
     * @param  Closure(): TResult  $closePerson
     * @return TResult
     */
    public function run(TenantContext $context, ?int $accountId, Closure $closePerson): mixed;
}
