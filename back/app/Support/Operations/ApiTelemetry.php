<?php

namespace App\Support\Operations;

use App\Contracts\Operations\ApiMetrics;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Log\LogManager;
use Throwable;

final readonly class ApiTelemetry
{
    public function __construct(private ApiMetrics $metrics, private Repository $config, private LogManager $logs) {}

    public function observe(ApiMeasurement $measurement): void
    {
        if ($this->config->get('operations.api.enabled') !== true) {
            return;
        }
        try {
            if ($this->metrics->record($measurement)) {
                $this->logs->channel('operations')->warning('ops.api.request_review', $measurement->toArray());
            }
        } catch (Throwable) {
            // No telemetry failure may replace the HTTP response or expose its exception.
        }
    }
}
