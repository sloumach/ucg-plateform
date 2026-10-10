<?php

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Modules\Tenancy\Application\Contracts\TenantScheduleQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use LogicException;

final readonly class LaravelTenantScheduleQueue implements TenantScheduleQueue
{
    public function __construct(private Dispatcher $bus, private Repository $config, private Application $app) {}

    private function connection(): string
    {
        $name = $this->config->get('tenancy.schedule_connection') ?? $this->config->get('queue.default');
        $driver = is_string($name) ? $this->config->get('queue.connections.'.$name.'.driver') : null;
        if (! is_string($name) || (! in_array($driver, ['database', 'redis'], true)
            && ! ($driver === 'sync' && $this->app->runningUnitTests()))) {
            throw new LogicException('Tenant scheduled work requires a durable database or Redis queue.');
        }

        return $name;
    }

    public function tenant(JobContext $context, string $task, string $runId): void
    {
        $this->bus->dispatch((new RunTenantScheduledTask($context, $task, $runId))->onConnection($this->connection()));
    }

    public function page(string $task, string $runId, ?string $after = null): void
    {
        $this->bus->dispatch((new DispatchTenantSchedulePage($task, $runId, $after))->onConnection($this->connection()));
    }
}
