<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Fixtures\Jobs\TenantContextProbe;
use Tests\TestCase;

class TenantQueueRedisTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_real_redis_queue_retry_preserves_tenant_and_does_not_block_other_work(): void
    {
        if (! config('tenancy.test_redis')) {
            $this->markTestSkipped('Real Redis queue integration requires TENANCY_TEST_REDIS=true (enabled in CI).');
        }
        $this->freezeTime();
        $queueName = 'ucg-ten006-test-'.Str::uuid();
        config(['tenancy.cache_store' => 'redis', 'database.redis.client' => 'predis',
            'cache.prefix' => $queueName.':', 'queue.connections.redis.block_for' => null]);
        Cache::purge('redis');
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $resolver = app(TenantResolver::class);
        $a = $resolver->resolve($first->id, '', $first->owner_user_id, (string) Str::uuid());
        $b = $resolver->resolve($second->id, '', $second->owner_user_id, (string) Str::uuid());
        $cache = app(TenantCache::class);
        $queue = app(QueueManager::class)->connection('redis');
        $this->assertInstanceOf(RedisQueue::class, $queue);
        Exceptions::fake();
        try {
            $bus = app(Dispatcher::class);
            $bus->dispatch((new TenantContextProbe($a->toJobContext(), 'first', true))->onConnection('redis')->onQueue($queueName));
            $bus->dispatch((new TenantContextProbe($b->toJobContext(), 'second'))->onConnection('redis')->onQueue($queueName));

            $this->work($queueName);
            $this->assertFalse(app(TenantExecutionScope::class)->active());
            $this->work($queueName);
            $this->travel(5)->seconds();
            $this->work($queueName);

            Exceptions::assertReported(RuntimeException::class);
            $this->assertSame('first', $cache->get($a, 'probe:123'));
            $this->assertSame('second', $cache->get($b, 'probe:123'));
            $this->assertSame(0, $queue->size($queueName));
            $this->assertDatabaseCount('failed_jobs', 0);
        } finally {
            $cache->forget($a, 'probe:123');
            $cache->forget($a, 'probe:attempt');
            $cache->forget($b, 'probe:123');
            $queue->clear($queueName);
        }
    }

    private function work(string $queueName): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'redis', '--queue' => $queueName, '--once' => true, '--sleep' => 0]));
    }
}
