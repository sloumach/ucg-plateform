<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Modules\Tenancy\Application\Contracts\TenantScheduledTask;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use LogicException;

final readonly class TenantScheduleRegistry
{
    public function __construct(private Repository $config, private Container $container) {}

    /** @return array<string, class-string<TenantScheduledTask>> */
    private function entries(): array
    {
        $entries = $this->config->get('tenancy.scheduled_tasks', []);
        if (! is_array($entries) || count($entries) > 64) {
            throw new LogicException('Tenant scheduled tasks must be a bounded server-side registry.');
        }
        foreach ($entries as $name => $class) {
            if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name)
                || ! is_string($class) || ! is_a($class, TenantScheduledTask::class, true)) {
                throw new LogicException('Invalid tenant scheduled task registration.');
            }
        }

        return $entries;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entries());
    }

    public function task(string $name): TenantScheduledTask
    {
        $class = $this->entries()[$name] ?? throw new TenantExecutionRejectedException('TENANT_TASK_UNREGISTERED');
        $task = $this->container->make($class);
        if (! $task instanceof TenantScheduledTask) {
            throw new LogicException('Invalid tenant scheduled task binding.');
        }

        return $task;
    }
}
