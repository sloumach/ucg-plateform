<?php

namespace App\Support\Operations;

use App\Contracts\Operations\RuntimeProbe;
use Illuminate\Contracts\Config\Repository;
use Throwable;

final readonly class RuntimeDiagnostics
{
    public function __construct(private RuntimeProbe $probe, private Repository $config) {}

    public function inspect(bool $requested): RuntimeInspection
    {
        $checks = [];
        $components = ['database', 'cache', 'queues', 'failed_jobs', 'private_storage'];
        if (! $requested) {
            foreach ($components as $component) {
                $checks[$component] = new RuntimeCheck(ProbeState::Unverified, 'PROBE_NOT_REQUESTED');
            }

            return new RuntimeInspection($checks, false);
        }

        $limit = $this->config->get('operations.monitoring.queue_sample_limit');
        $warning = $this->config->get('operations.monitoring.ready_wait_warning_seconds');
        if (! is_int($limit) || $limit < 1 || $limit > 10000 || ! is_int($warning) || $warning < 1 || $warning > 86400) {
            return new RuntimeInspection(['configuration' => new RuntimeCheck(ProbeState::Failed, 'MONITORING_CONFIGURATION_INVALID')], true);
        }

        $readers = [
            'database' => fn (): RuntimeCheck => $this->probe->database(),
            'cache' => fn (): RuntimeCheck => $this->probe->cache(),
            'queues' => fn (): RuntimeCheck => $this->probe->queues($limit, $warning),
            'failed_jobs' => fn (): RuntimeCheck => $this->probe->failedJobs($limit),
            'private_storage' => fn (): RuntimeCheck => $this->probe->privateStorage(),
        ];
        foreach ($readers as $component => $read) {
            try {
                $checks[$component] = $read();
            } catch (Throwable) {
                // Connection exceptions can contain credentials, paths and query bindings.
                $checks[$component] = new RuntimeCheck(ProbeState::Failed, strtoupper($component).'_READ_FAILED');
            }
        }

        return new RuntimeInspection($checks, true);
    }
}
