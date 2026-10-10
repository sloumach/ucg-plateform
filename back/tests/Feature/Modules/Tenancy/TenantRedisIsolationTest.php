<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantRedisIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_real_redis_isolates_cache_lock_and_rate_budgets_with_identical_functional_keys(): void
    {
        if (! config('tenancy.test_redis')) {
            $this->markTestSkipped('Dedicated Redis integration is enabled in CI with TENANCY_TEST_REDIS=true.');
        }
        config(['tenancy.cache_store' => 'redis', 'cache.prefix' => 'ucg-ten005-test-'.Str::uuid().':']);
        Cache::purge('redis');
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $resolver = app(TenantResolver::class);
        $a = $resolver->resolve($first->id, '', $first->owner_user_id, (string) Str::uuid());
        $b = $resolver->resolve($second->id, '', $second->owner_user_id, (string) Str::uuid());
        $cache = app(TenantCache::class);
        $limiter = new RateLimiter(Cache::store('redis'));
        $rateA = TenantResourceNamespace::key($a->organizationId, 'rate', 'integration');
        $rateB = TenantResourceNamespace::key($b->organizationId, 'rate', 'integration');
        try {
            $cache->put($a, 'report:123', 'first', 60);
            $cache->put($b, 'report:123', 'second', 60);
            $this->assertSame('first', $cache->get($a, 'report:123'));
            $this->assertSame('second', $cache->get($b, 'report:123'));
            $cache->synchronized($a, 'report:123', 30, function () use ($cache, $a, $b): void {
                $this->assertSame('second lock', $cache->synchronized($b, 'report:123', 30, fn () => 'second lock'));
                try {
                    $cache->synchronized($a, 'report:123', 30, fn () => 'unexpected acquisition');
                    $this->fail('Redis must refuse an already owned tenant lock.');
                } catch (DomainConflictException $exception) {
                    $this->assertSame('TENANT_RESOURCE_BUSY', $exception->errorCode());
                }
            });
            $limiter->hit($rateA, 60);
            $this->assertTrue($limiter->tooManyAttempts($rateA, 1));
            $this->assertFalse($limiter->tooManyAttempts($rateB, 1));
            $cache->forget($a, 'report:123');
            $this->assertSame('second', $cache->get($b, 'report:123'));
        } finally {
            $cache->forget($a, 'report:123');
            $cache->forget($b, 'report:123');
            $limiter->clear($rateA);
            $limiter->clear($rateB);
        }
    }
}
