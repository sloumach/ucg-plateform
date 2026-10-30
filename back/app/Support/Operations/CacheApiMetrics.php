<?php

namespace App\Support\Operations;

use App\Contracts\Operations\ApiMetrics;
use Illuminate\Contracts\Cache\Factory;
use RuntimeException;

final readonly class CacheApiMetrics implements ApiMetrics
{
    private const COUNTERS = ['total', 'errors', 'slow', 'duration_ms'];

    public function __construct(private Factory $cache, private MonitoringScope $scope) {}

    public function record(ApiMeasurement $measurement): bool
    {
        $slow = $this->scope->integer('api.slow_ms', 1, 60000);
        $logLimit = $this->scope->integer('api.log_limit_per_minute', 0, 1000);
        $prefix = 'ops:'.$this->scope->namespace().':api:'.intdiv(now()->getTimestamp(), 60).':';
        $store = $this->cache->store($this->scope->store());
        foreach (['total' => 1, 'errors' => $measurement->status >= 500 ? 1 : 0,
            'slow' => $measurement->durationMs >= $slow ? 1 : 0, 'duration_ms' => $measurement->durationMs] as $counter => $amount) {
            $store->add($prefix.$counter, 0, 7200);
            if ($store->increment($prefix.$counter, $amount) === false) {
                throw new RuntimeException('API_COUNTER_WRITE_FAILED');
            }
        }
        if ($measurement->status < 500 && $measurement->durationMs < $slow) {
            return false;
        }
        $store->add($prefix.'logged', 0, 7200);
        $logged = $store->increment($prefix.'logged');

        return $logged !== false && $logged <= $logLimit;
    }

    public function inspect(): RuntimeCheck
    {
        $minutes = $this->scope->integer('api.window_minutes', 1, 60);
        $minimum = $this->scope->integer('api.minimum_samples', 1, 1000000);
        $errorRate = $this->scope->integer('api.error_percent', 1, 100);
        $slowRate = $this->scope->integer('api.slow_percent', 1, 100);
        $this->scope->integer('api.slow_ms', 1, 60000);
        $prefix = 'ops:'.$this->scope->namespace().':api:';
        $store = $this->cache->store($this->scope->store());
        $total = array_fill_keys(self::COUNTERS, 0);
        $minute = intdiv(now()->getTimestamp(), 60);
        for ($offset = 0; $offset < $minutes; $offset++) {
            $values = $store->many(array_map(fn (string $key): string => $prefix.($minute - $offset).':'.$key, self::COUNTERS));
            $present = array_filter($values, static fn (mixed $value): bool => $value !== null);
            if ($present !== [] && count($present) !== count(self::COUNTERS)) {
                return new RuntimeCheck(ProbeState::Unverified, 'API_COUNTER_INCOMPLETE');
            }
            foreach (self::COUNTERS as $key) {
                $value = $values[$prefix.($minute - $offset).':'.$key] ?? 0;
                if (! is_int($value) || $value < 0) {
                    return new RuntimeCheck(ProbeState::Failed, 'API_COUNTER_INVALID');
                }
                $total[$key] += $value;
            }
        }
        if ($total['errors'] > $total['total'] || $total['slow'] > $total['total']) {
            return new RuntimeCheck(ProbeState::Failed, 'API_COUNTER_INCONSISTENT');
        }
        $enough = $total['total'] >= $minimum;
        $warning = $enough && ($total['errors'] * 100 >= $total['total'] * $errorRate
            || $total['slow'] * 100 >= $total['total'] * $slowRate);

        return new RuntimeCheck($warning ? ProbeState::Warning : ($enough ? ProbeState::Passed : ProbeState::Unverified),
            $warning ? 'API_ERROR_OR_LATENCY_REVIEW' : ($enough ? 'API_WINDOW_OBSERVED' : 'API_SAMPLES_INSUFFICIENT'),
            ['observed' => $total, 'window_minutes' => $minutes, 'minimum_samples' => $minimum,
                'error_warning_percent' => $errorRate, 'slow_warning_percent' => $slowRate,
                'scope' => 'approximate_minute_buckets', 'percentiles_verified' => false]);
    }
}
