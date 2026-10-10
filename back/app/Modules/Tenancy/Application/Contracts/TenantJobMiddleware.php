<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Closure;
use Illuminate\Mail\SendQueuedMailable;

interface TenantJobMiddleware
{
    /** @param Closure(TenantJob|SendQueuedMailable): mixed $next */
    public function handle(TenantJob|SendQueuedMailable $job, Closure $next): mixed;
}
