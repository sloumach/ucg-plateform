<?php

namespace App\Http\Middleware;

use App\Http\Api\RequestId;
use App\Support\Operations\ApiMeasurement;
use App\Support\Operations\ApiTelemetry;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ObserveApiRequest
{
    public function __construct(private Repository $config, private ApiTelemetry $telemetry) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->config->get('operations.api.enabled') !== true || ! $request->is('api/*')) {
            return $next($request);
        }
        $start = hrtime(true);
        try {
            return $next($request);
        } finally {
            $request->attributes->set('ops_duration_ms', min(3600000, max(0, (int) ((hrtime(true) - $start) / 1000000))));
        }
    }

    public function terminate(Request $request, Response $response): void
    {
        $duration = $request->attributes->get('ops_duration_ms');
        if (is_int($duration) && $request->is('api/*')) {
            $this->telemetry->observe(new ApiMeasurement(RequestId::for($request), $response->getStatusCode(), $duration));
        }
    }
}
