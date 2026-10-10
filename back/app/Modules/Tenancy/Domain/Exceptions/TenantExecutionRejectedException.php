<?php

namespace App\Modules\Tenancy\Domain\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class TenantExecutionRejectedException extends RuntimeException implements ShouldntReport
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__('tenancy.errors.background_rejected'));
    }
}
