<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface TenantDataVisibility
{
    /**
     * Project immediately before delivery; never serialize an authorization decision into a job or shared cache.
     *
     * @param  array<string, FieldVisibility>  $fields
     * @return array<string, mixed>
     */
    public function project(TenantContext $context, TenantDataRecord $record, array $fields): array;

    /**
     * A bounded page/chunk shares checks only within this call; the next call rechecks current grants.
     *
     * @param  list<TenantDataRecord>  $records
     * @param  array<string, FieldVisibility>  $fields
     * @return list<array<string, mixed>>
     */
    public function projectMany(TenantContext $context, array $records, array $fields): array;
}
