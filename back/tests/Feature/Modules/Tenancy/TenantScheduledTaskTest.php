<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantScheduleQueue;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Application\Services\TenantScheduleDispatcher;
use App\Modules\Tenancy\Application\Services\TenantScheduleRegistry;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Infrastructure\Queue\DispatchTenantSchedulePage;
use App\Modules\Tenancy\Infrastructure\Queue\RestoreTenantJobContext;
use App\Modules\Tenancy\Infrastructure\Queue\RunTenantScheduledTask;
use App\Support\Cache\TenantCacheStore;
use App\Support\Queue\JobContext;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\Jobs\ScheduledContextProbe;
use Tests\TestCase;

class TenantScheduledTaskTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_identical_task_keys_are_isolated_and_completed_retries_are_skipped(): void
    {
        $this->configureTask();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $run = (string) Str::uuid();
        $a = new RunTenantScheduledTask(new JobContext($run, $first->id), 'probe', $run);
        $b = new RunTenantScheduledTask(new JobContext($run, $second->id), 'probe', $run);

        app(Dispatcher::class)->dispatchSync($a);
        app(Dispatcher::class)->dispatchSync($b);
        app(Dispatcher::class)->dispatchSync(unserialize(serialize($a)));

        $this->assertSame(['runs' => 1, 'tenant' => $first->id, 'actor' => null, 'run' => $run, 'timezone' => 'UTC'], $this->probeResult($first));
        $this->assertSame(['runs' => 1, 'tenant' => $second->id, 'actor' => null, 'run' => $run, 'timezone' => 'UTC'], $this->probeResult($second));
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_busy_tenant_is_released_without_blocking_another_tenant(): void
    {
        $this->configureTask();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $store = app(TenantCacheStore::class);
        $lock = $store->lock(TenantResourceNamespace::key($first->id, 'lock', 'schedule:probe'), 90);
        $this->assertTrue($lock->get());
        $run = (string) Str::uuid();
        $busy = (new RunTenantScheduledTask(new JobContext($run, $first->id), 'probe', $run))->withFakeQueueInteractions();

        try {
            app(RestoreTenantJobContext::class)->handle($busy, fn () => app()->call([$busy, 'handle']));
            app(Dispatcher::class)->dispatchSync(new RunTenantScheduledTask(new JobContext($run, $second->id), 'probe', $run));
        } finally {
            $lock->release();
        }

        $busy->assertReleased(5);
        $this->assertNull($this->probeResult($first));
        $this->assertSame(1, $this->probeResult($second)['runs'] ?? null);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_scheduled_failure_retries_its_original_tenant_and_other_tenants_complete(): void
    {
        $this->configureTask();
        $this->freezeTime();
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $run = (string) Str::uuid();
        $cache = app(TenantCacheStore::class)->repository();
        $key = TenantResourceNamespace::key($first->id, 'cache', 'schedule-probe:fail');
        $cache->put($key, true, 60);
        app(TenantScheduleQueue::class)->tenant(new JobContext($run, $first->id), 'probe', $run);
        app(TenantScheduleQueue::class)->tenant(new JobContext($run, $second->id), 'probe', $run);
        Exceptions::fake();

        $this->work();
        $this->work();
        $this->assertNull($this->probeResult($first));
        $this->assertSame(1, $this->probeResult($second)['runs'] ?? null);
        $cache->forget($key);
        $this->travel(5)->seconds();
        $this->work();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertSame($first->id, $this->probeResult($first)['tenant'] ?? null);
        $this->assertSame($run, $this->probeResult($first)['run']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_tenant_suspended_after_fanout_is_refused_at_execution(): void
    {
        $this->configureTask();
        $organization = Organization::factory()->create();
        $run = (string) Str::uuid();
        app(TenantScheduleQueue::class)->tenant(new JobContext($run, $organization->id), 'probe', $run);
        DB::table('organizations')->where('id', $organization->id)->update(['status' => 'suspended']);

        $this->work();

        $this->assertNull($this->probeResult($organization));
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_fanout_pages_cover_all_active_tenants_with_a_stable_run_and_no_offset_pagination(): void
    {
        $this->configureTask();
        $owner = User::factory()->create();
        Organization::factory()->count(201)->recycle($owner)->create();
        Organization::factory()->suspended()->recycle($owner)->create();
        $expected = Organization::query()->where('status', 'active')->orderBy('id')->pluck('id')->all();
        $run = (string) Str::uuid();
        $fake = Queue::fake([RunTenantScheduledTask::class, DispatchTenantSchedulePage::class]);

        app(TenantScheduleDispatcher::class)->page('probe', $run, null);

        Queue::assertPushed(RunTenantScheduledTask::class, 200);
        Queue::assertPushed(DispatchTenantSchedulePage::class, fn ($job) => $job->runId === $run && $job->after === $expected[199]);
        $continuation = $fake->pushed(DispatchTenantSchedulePage::class)->first();
        $this->assertInstanceOf(DispatchTenantSchedulePage::class, $continuation);
        $continuation->handle(app(TenantScheduleDispatcher::class));
        Queue::assertPushed(RunTenantScheduledTask::class, 201);
        $jobs = $fake->pushed(RunTenantScheduledTask::class);
        $this->assertSame($expected, $jobs->map(fn ($job) => $job->context->tenantId)->all());
        $this->assertSame([$run], $jobs->map(fn ($job) => $job->runId)->unique()->values()->all());
        app(TenantScheduleDispatcher::class)->page('probe', $run, null);
        Queue::assertPushed(RunTenantScheduledTask::class, 201);
        Queue::assertPushed(DispatchTenantSchedulePage::class, 1);
    }

    public function test_dispatch_failure_does_not_prevent_other_tenants_being_queued(): void
    {
        $this->configureTask();
        Organization::factory()->count(2)->create();
        $ids = Organization::query()->orderBy('id')->pluck('id')->all();
        $queue = $this->mock(TenantScheduleQueue::class);
        $queue->shouldReceive('tenant')->once()->withArgs(fn (JobContext $context) => $context->tenantId === $ids[0])
            ->andThrow(new RuntimeException('Queue transport unavailable for first tenant.'));
        $queue->shouldReceive('tenant')->once()->withArgs(fn (JobContext $context) => $context->tenantId === $ids[1]);

        $this->expectException(RuntimeException::class);
        app(TenantScheduleDispatcher::class)->page('probe', (string) Str::uuid(), null);
    }

    public function test_empty_active_set_creates_no_jobs(): void
    {
        $this->configureTask();
        Organization::factory()->suspended()->create();
        Queue::fake([RunTenantScheduledTask::class, DispatchTenantSchedulePage::class]);

        app(TenantScheduleDispatcher::class)->page('probe', (string) Str::uuid(), null);

        Queue::assertNothingPushed();
    }

    public function test_page_retry_only_queues_the_failed_dispatch_and_not_previous_successes(): void
    {
        $this->configureTask();
        Organization::factory()->count(2)->create();
        $ids = Organization::query()->orderBy('id')->pluck('id')->all();
        $run = (string) Str::uuid();
        $queue = $this->mock(TenantScheduleQueue::class);
        $queue->shouldReceive('tenant')->once()->ordered()
            ->withArgs(fn (JobContext $context) => $context->tenantId === $ids[0])
            ->andThrow(new RuntimeException('First dispatch fails.'));
        $queue->shouldReceive('tenant')->once()->ordered()->withArgs(fn (JobContext $context) => $context->tenantId === $ids[1]);
        $queue->shouldReceive('tenant')->once()->ordered()->withArgs(fn (JobContext $context) => $context->tenantId === $ids[0]);

        try {
            app(TenantScheduleDispatcher::class)->page('probe', $run, null);
            $this->fail('A failed dispatch must request a page retry.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Some tenant tasks could not be queued; retry the page.', $exception->getMessage());
        }
        app(TenantScheduleDispatcher::class)->page('probe', $run, null);
        app(TenantScheduleDispatcher::class)->page('probe', $run, null);

        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_removing_task_registration_refuses_already_queued_work(): void
    {
        $this->configureTask();
        $organization = Organization::factory()->create();
        $run = (string) Str::uuid();
        app(TenantScheduleQueue::class)->tenant(new JobContext($run, $organization->id), 'probe', $run);
        config(['tenancy.scheduled_tasks' => []]);

        $this->work();

        $this->assertNull($this->probeResult($organization));
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_dispatch_is_deferred_until_commit_and_discarded_on_rollback(): void
    {
        $this->configureTask();
        $organization = Organization::factory()->create();
        $run = (string) Str::uuid();

        try {
            DB::transaction(function () use ($organization, $run): void {
                app(TenantScheduleQueue::class)->tenant(new JobContext($run, $organization->id), 'probe', $run);
                $this->assertDatabaseCount('jobs', 0);
                throw new RuntimeException('Cancel transaction.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Cancel transaction.', $exception->getMessage());
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull($this->probeResult($organization));
    }

    public function test_registered_schedule_has_guards_and_its_command_queues_work(): void
    {
        $this->configureTask();
        Queue::fake([DispatchTenantSchedulePage::class]);
        require base_path('routes/console.php');
        $events = array_values(array_filter(app(Schedule::class)->events(), fn ($event) => $event->description === 'tenant-schedule:probe'));
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->onOneServer);
        $this->assertTrue($events[0]->withoutOverlapping);

        $this->assertSame(0, Artisan::call('tenancy:dispatch-scheduled', ['task' => 'probe']));

        Queue::assertPushed(DispatchTenantSchedulePage::class, fn ($job) => $job->task === 'probe' && Str::isUuid($job->runId));
    }

    public function test_operator_command_refuses_unknown_task_and_non_durable_transport(): void
    {
        $this->configureTask();
        Queue::fake([DispatchTenantSchedulePage::class]);
        $this->assertSame(1, Artisan::call('tenancy:dispatch-scheduled', ['task' => 'unknown']));
        $this->assertStringContainsString(__('tenancy.schedule.failed'), Artisan::output());
        config(['tenancy.schedule_connection' => 'deferred']);

        $this->assertSame(1, Artisan::call('tenancy:dispatch-scheduled', ['task' => 'probe']));
        $this->assertStringContainsString(__('tenancy.schedule.failed'), Artisan::output());

        Queue::assertNothingPushed();
    }

    /** @return array<string, array{array<mixed>}> */
    public static function invalidRegistrations(): array
    {
        return ['unsafe name' => [['../task' => ScheduledContextProbe::class]], 'non task' => [['probe' => Organization::class]],
            'client-like payload' => [['probe' => ['class' => ScheduledContextProbe::class]]]];
    }

    /** @param array<string, mixed> $entries */
    #[DataProvider('invalidRegistrations')]
    public function test_task_registry_refuses_unsafe_or_non_contract_handlers(array $entries): void
    {
        config(['tenancy.scheduled_tasks' => $entries]);

        $this->expectException(LogicException::class);
        app(TenantScheduleRegistry::class)->names();
    }

    private function configureTask(): void
    {
        config(['tenancy.scheduled_tasks' => ['probe' => ScheduledContextProbe::class],
            'tenancy.cache_store' => 'database', 'tenancy.schedule_connection' => 'database']);
    }

    /** @return array{runs: int, tenant: string, actor: int|null, run: string, timezone: string}|null */
    private function probeResult(Organization $organization): ?array
    {
        return app(TenantCacheStore::class)->repository()->get(TenantResourceNamespace::key($organization->id, 'cache', 'schedule-probe:123'));
    }

    private function work(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0]));
    }
}
