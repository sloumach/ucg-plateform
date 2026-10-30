<?php

namespace App\Support\Operations;

use Illuminate\Contracts\Config\Repository;
use InvalidArgumentException;

final readonly class MonitoringScope
{
    public function __construct(private Repository $config) {}

    public function namespace(): string
    {
        $value = $this->config->get('operations.resource_namespace');
        if (! is_string($value) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,62}\z/', $value) !== 1) {
            throw new InvalidArgumentException('MONITORING_NAMESPACE_INVALID');
        }

        return $value;
    }

    public function store(): string
    {
        $store = $this->config->get('operations.api.cache_store');
        if (! is_string($store) || preg_match('/\A[a-zA-Z_][a-zA-Z0-9_-]{0,62}\z/', $store) !== 1
            || ! in_array($this->config->get('cache.stores.'.$store.'.driver'), ['redis', 'database'], true)) {
            throw new InvalidArgumentException('MONITORING_STORE_INVALID');
        }

        return $store;
    }

    public function integer(string $key, int $min, int $max): int
    {
        $value = $this->config->get('operations.'.$key);
        if (! is_int($value) || $value < $min || $value > $max) {
            throw new InvalidArgumentException('MONITORING_LIMIT_INVALID');
        }

        return $value;
    }
}
