<?php

namespace App\Modules\Tenancy\Infrastructure\Queue;

use App\Modules\Tenancy\Application\Services\TenantScheduleDispatcher;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Global operator-only fan-out: reads IDs, never runs tenant business logic. */
final class DispatchTenantSchedulePage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly string $task, public readonly string $runId, public readonly ?string $after = null)
    {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    public function handle(TenantScheduleDispatcher $dispatcher): void
    {
        $dispatcher->page($this->task, $this->runId, $this->after);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['request:'.$this->runId, 'tenant-schedule:'.$this->task];
    }
}
