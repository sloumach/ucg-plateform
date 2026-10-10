<?php

namespace App\Modules\Tenancy\Domain\Contracts;

interface AccessAuditRepository
{
    /** @param array<string, mixed> $attributes */
    public function record(array $attributes): void;
}
