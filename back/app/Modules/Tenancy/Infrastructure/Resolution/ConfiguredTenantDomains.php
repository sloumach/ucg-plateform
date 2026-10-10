<?php

namespace App\Modules\Tenancy\Infrastructure\Resolution;

use App\Modules\Tenancy\Domain\Contracts\ApprovedTenantDomains;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final readonly class ConfiguredTenantDomains implements ApprovedTenantDomains
{
    public function __construct(private Repository $config) {}

    public function organizationIdFor(string $host): ?string
    {
        $domains = $this->config->get('tenancy.approved_domains', []);
        if (! is_array($domains)) {
            throw new ModelNotFoundException;
        }
        $host = strtolower($host);
        if (! array_key_exists($host, $domains)) {
            return null;
        }
        $organizationId = $domains[$host];
        // A malformed approval must fail closed, not fall back to another source.
        if (! is_string($organizationId) || ! Str::isUuid($organizationId)) {
            throw new ModelNotFoundException;
        }

        return strtolower($organizationId);
    }
}
