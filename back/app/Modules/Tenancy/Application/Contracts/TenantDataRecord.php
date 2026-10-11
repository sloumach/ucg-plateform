<?php

namespace App\Modules\Tenancy\Application\Contracts;

/** Candidate data prepared by the owning module, never taken from an HTTP payload. */
final readonly class TenantDataRecord
{
    /** @param array<string, mixed> $data */
    public function __construct(public string $organizationId, public array $data,
        public string $scopeType = 'organization', public ?string $scopeId = null) {}
}
