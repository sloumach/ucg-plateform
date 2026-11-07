<?php

namespace App\Support\Communication;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Database\Query\Builder;

interface MediaContextSource extends ContextSource
{
    /** @return array{visible: Builder|null, shared: Builder|null, scrim: Builder|null} */
    public function constraints(TenantContext $context): array;

    /** @param list<string> $ids
     * @return array<string, array{id: string, label: string, version: string, is_scrim: bool, visibility: string}> */
    public function metadata(TenantContext $context, array $ids): array;
}
