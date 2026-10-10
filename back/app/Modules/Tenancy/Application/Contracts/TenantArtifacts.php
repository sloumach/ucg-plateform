<?php

namespace App\Modules\Tenancy\Application\Contracts;

/** Internal port: callers must additionally authorize their business object and validate its content. */
interface TenantArtifacts
{
    public function store(TenantContext $context, string $name, string $contents): TenantArtifactData;

    public function read(TenantContext $context, string $artifactId): string;

    public function delete(TenantContext $context, string $artifactId): void;

    public function usedBytes(TenantContext $context): int;
}
