<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Queue\JobContext;

interface TenantScheduleQueue
{
    public function tenant(JobContext $context, string $task, string $runId): void;

    public function page(string $task, string $runId, ?string $after = null): void;
}
