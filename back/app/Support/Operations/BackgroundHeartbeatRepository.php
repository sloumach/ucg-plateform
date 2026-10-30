<?php

namespace App\Support\Operations;

use App\Contracts\Operations\BackgroundHeartbeats;
use Illuminate\Contracts\Cache\Factory;
use RuntimeException;

final readonly class BackgroundHeartbeatRepository implements BackgroundHeartbeats
{
    public function __construct(private Factory $cache) {}

    public function record(BackgroundSettings $settings, string $component): void
    {
        // Bounded keys, no TTL: an old marker remains distinguishable from a missing one.
        if (! $this->cache->store($settings->store)->forever($settings->key($component), now()->getTimestamp())) {
            throw new RuntimeException('Background heartbeat write failed.');
        }
    }

    public function read(BackgroundSettings $settings, string $component): RuntimeCheck
    {
        $timestamp = $this->cache->store($settings->store)->get($settings->key($component));
        if ($timestamp === null) {
            return new RuntimeCheck(ProbeState::Warning, 'BACKGROUND_HEARTBEAT_MISSING');
        }
        $now = now()->getTimestamp();
        if (! is_int($timestamp) || $timestamp < 1 || $timestamp > $now) {
            return new RuntimeCheck(ProbeState::Failed, 'BACKGROUND_HEARTBEAT_INVALID');
        }
        $age = $now - $timestamp;

        return new RuntimeCheck($age >= $settings->heartbeatWarningSeconds ? ProbeState::Warning : ProbeState::Passed,
            $age >= $settings->heartbeatWarningSeconds ? 'BACKGROUND_ACTIVITY_STALE' : 'BACKGROUND_ACTIVITY_OBSERVED',
            ['age_seconds' => $age, 'warning_seconds' => $settings->heartbeatWarningSeconds,
                'all_processes_verified' => false, 'business_success_verified' => false]);
    }
}
