<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Queue\JobContext;
use App\Support\Queue\QueueName;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Use dispatch/dispatchSync, never dispatchNow or a direct handle call. */
abstract class TenantJob implements ShouldQueue, TenantQueuedWork
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> */
    public array $backoff = [5, 30, 120];

    public function __construct(public readonly JobContext $context)
    {
        $this->onQueue(QueueName::Default->value);
        $this->afterCommit();
    }

    final public function jobContext(): JobContext
    {
        return $this->context;
    }

    /** @return list<class-string<TenantJobMiddleware>> */
    final public function middleware(): array
    {
        return [TenantJobMiddleware::class];
    }

    /** @return list<string> */
    public function tags(): array
    {
        return $this->context->tags();
    }
}
