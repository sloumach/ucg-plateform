<?php

namespace App\Support\Operations;

use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ApiMeasurement
{
    public function __construct(public string $requestId, public int $status, public int $durationMs)
    {
        if (! Str::isUuid($requestId) || $status < 100 || $status > 599 || $durationMs < 0 || $durationMs > 3600000) {
            throw new InvalidArgumentException('API_MEASUREMENT_INVALID');
        }
    }

    /** @return array{request_id: string, status: int, duration_ms: int} */
    public function toArray(): array
    {
        return ['request_id' => $this->requestId, 'status' => $this->status, 'duration_ms' => $this->durationMs];
    }
}
