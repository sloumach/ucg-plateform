<?php

namespace App\Support\Cache;

use Illuminate\Cache\CacheManager;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use LogicException;

final readonly class TenantCacheStore
{
    public function __construct(private CacheManager $cache, private ConfigRepository $config, private Application $app) {}

    public function repository(): Repository
    {
        $name = $this->config->get('tenancy.cache_store');
        $driver = is_string($name) ? $this->config->get('cache.stores.'.$name.'.driver') : null;
        if (! is_string($name) || ! in_array($driver, ['redis', 'database', 'array'], true)
            || ($driver === 'array' && ! $this->app->runningUnitTests())) {
            throw new LogicException('Tenant resources require a shared non-failover Redis/database store (array is test-only).');
        }

        return $this->cache->store($name);
    }

    public function lock(string $key, int $seconds): Lock
    {
        $backend = $this->repository()->getStore();
        if ($backend instanceof DatabaseStore) {
            $connection = $backend->getLockConnection();
            if (! $connection instanceof Connection) {
                throw new LogicException('A database tenant lock requires a Laravel database connection.');
            }
            $name = $this->config->get('tenancy.cache_store');
            $table = $this->config->get('cache.stores.'.$name.'.lock_table') ?? 'cache_locks';
            if (! is_string($table)) {
                throw new LogicException('The tenant lock table must be configured.');
            }

            return new TransactionSafeDatabaseLock($connection, $table, $backend->getPrefix().$key, $seconds, lottery: null);
        }
        if (! $backend instanceof LockProvider) {
            throw new LogicException('Tenant resources require atomic lock support.');
        }

        return $backend->lock($key, $seconds);
    }
}
