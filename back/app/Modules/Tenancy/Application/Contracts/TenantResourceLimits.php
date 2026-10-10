<?php

namespace App\Modules\Tenancy\Application\Contracts;

interface TenantResourceLimits
{
    public function value(TenantContext $context, string $name): int;

    public function assertWithin(TenantContext $context, string $name, int $amount): void;

    public function assertImport(TenantContext $context, int $bytes, int $rows): void;

    public function assertExport(TenantContext $context, int $rows): void;
}
