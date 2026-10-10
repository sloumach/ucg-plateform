<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Queue\JobContext;

interface TenantQueuedWork
{
    public function jobContext(): JobContext;
}
