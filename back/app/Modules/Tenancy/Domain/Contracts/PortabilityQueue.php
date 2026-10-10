<?php

namespace App\Modules\Tenancy\Domain\Contracts;

interface PortabilityQueue
{
    public function export(string $organizationId, string $operationId): void;
}
