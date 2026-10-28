<?php

namespace App\Modules\Competition\Application\Contracts;

use App\Modules\Tenancy\Application\Contracts\TenantContext;

interface DataPreparationReferences
{
    /** Checks only the explicitly requested identifiers/keys, never import eligibility.
     * @param  array<int,array{kind:string,value:string}>  $checks
     * @return array<int,bool>
     */
    public function lookup(TenantContext $context, array $checks): array;
}
