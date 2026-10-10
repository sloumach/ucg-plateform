<?php

namespace App\Support\Storage;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class TenantResourceNamespace
{
    public static function key(string $organizationId, string $purpose, string $key): string
    {
        if (! Str::isUuid($organizationId) || ! in_array($purpose, ['cache', 'lock', 'rate'], true) || trim($key) === '') {
            throw new InvalidArgumentException('A resource key requires a tenant UUID, a known purpose and a non-empty key.');
        }

        return 'tenant:'.strtolower($organizationId).':'.$purpose.':'.hash('sha256', $key);
    }

    public static function path(string $tenantId, string $relativePath): string
    {
        if (strlen($tenantId) > 128 || ! preg_match('/\A[a-zA-Z0-9_-]+\z/D', $tenantId)) {
            throw new InvalidArgumentException('The tenant identifier is not storage-safe.');
        }
        if (Str::isUuid($tenantId)) {
            $tenantId = strtolower($tenantId);
        }
        if ($relativePath === '' || strlen($relativePath) > 512
            || preg_match('/[\x00-\x1f\x7f\\\\:%]/', $relativePath)
            || array_intersect(explode('/', $relativePath), ['', '.', '..']) !== []) {
            throw new InvalidArgumentException('The artifact path is not storage-safe.');
        }

        return 'tenants/'.$tenantId.'/'.$relativePath;
    }
}
