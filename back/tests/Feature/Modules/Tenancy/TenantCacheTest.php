<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TenantCacheTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_identical_cache_keys_and_invalidation_are_isolated_between_tenants(): void
    {
        config(['tenancy.cache_store' => 'database']);
        $first = $this->context();
        $second = $this->context();
        $cache = app(TenantCache::class);
        $cache->put($first, 'report:123', false, 60);
        $cache->put($second, 'report:123', 'second', 60);

        $this->assertSame(false, $cache->get($first, 'report:123'));
        $this->assertSame('second', $cache->get($second, 'report:123'));
        $cache->forget($first, 'report:123');
        $this->assertNull($cache->get($first, 'report:123'));
        $this->assertSame('second', $cache->get($second, 'report:123'));
    }

    public function test_lock_contention_affects_only_its_tenant_and_releases_after_callback_failure(): void
    {
        config(['tenancy.cache_store' => 'database']);
        $first = $this->context();
        $second = $this->context();
        $cache = app(TenantCache::class);

        $result = $cache->synchronized($first, 'report:123', 30, function () use ($cache, $first, $second): string {
            $this->assertSame('other tenant', $cache->synchronized($second, 'report:123', 30, fn () => 'other tenant'));
            try {
                $cache->synchronized($first, 'report:123', 30, fn () => 'unexpected acquisition');
                $this->fail('A busy lock must refuse the operation.');
            } catch (DomainConflictException $exception) {
                $this->assertSame('TENANT_RESOURCE_BUSY', $exception->errorCode());
            }

            return 'first';
        });
        $this->assertSame('first', $result);
        try {
            $cache->synchronized($first, 'report:123', 30, fn () => throw new \RuntimeException('callback failure'));
        } catch (\RuntimeException $exception) {
            $this->assertSame('callback failure', $exception->getMessage());
        }
        $this->assertSame('released', $cache->synchronized($first, 'report:123', 30, fn () => 'released'));
        $this->assertDatabaseCount('cache_locks', 0);
    }

    public function test_cached_value_does_not_bypass_current_organization_status(): void
    {
        $context = $this->context();
        $cache = app(TenantCache::class);
        $cache->put($context, 'report', 'private', 60);
        Organization::query()->whereKey($context->organizationId)->update(['status' => 'suspended']);

        $this->expectException(DomainConflictException::class);
        $cache->get($context, 'report');
    }

    public function test_failover_store_is_refused_instead_of_splitting_lock_ownership(): void
    {
        $context = $this->context();
        config(['tenancy.cache_store' => 'failover']);

        $this->expectException(\LogicException::class);
        app(TenantCache::class)->synchronized($context, 'report', 30, fn () => 'must not run');
    }

    public function test_cache_expiration_does_not_expire_another_tenant_value(): void
    {
        $this->freezeTime();
        $first = $this->context();
        $second = $this->context();
        $cache = app(TenantCache::class);
        $cache->put($first, 'report', 'first', 1);
        $cache->put($second, 'report', 'second', 60);

        $this->travel(2)->seconds();

        $this->assertNull($cache->get($first, 'report'));
        $this->assertSame('second', $cache->get($second, 'report'));
    }

    public function test_an_expired_lock_owner_cannot_release_a_new_owner_lock(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database']);
        $context = $this->context();
        $store = app(TenantCacheStore::class);
        $key = TenantResourceNamespace::key($context->organizationId, 'lock', 'report');
        $previous = $store->lock($key, 1);
        $this->assertTrue($previous->get());
        $this->travel(2)->seconds();
        $current = $store->lock($key, 30);
        $this->assertTrue($current->get());

        $this->assertFalse($previous->release());

        $this->assertFalse($store->lock($key, 30)->get());
        $this->assertTrue($current->release());
        $this->assertDatabaseCount('cache_locks', 0);
    }

    private function context(): TenantContext
    {
        $organization = Organization::factory()->create();

        return app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
    }
}
