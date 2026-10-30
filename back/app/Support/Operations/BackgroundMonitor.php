<?php

namespace App\Support\Operations;

use App\Contracts\Operations\BackgroundHeartbeats;
use App\Contracts\Operations\BackgroundWorkloads;
use Illuminate\Contracts\Config\Repository;
use Throwable;

final readonly class BackgroundMonitor
{
    public function __construct(
        private Repository $config,
        private BackgroundHeartbeats $heartbeats,
        private BackgroundWorkloads $workloads,
    ) {}

    public function inspect(bool $probe): RuntimeInspection
    {
        if (! $probe) {
            return new RuntimeInspection(['background' => new RuntimeCheck(ProbeState::Unverified, 'PROBE_NOT_REQUESTED')], false);
        }
        if ($this->config->get('operations.background.enabled') !== true) {
            return new RuntimeInspection(['background' => new RuntimeCheck(ProbeState::Unverified, 'BACKGROUND_MONITORING_DISABLED')], true);
        }
        try {
            $settings = BackgroundSettings::from($this->config);
        } catch (Throwable) {
            return new RuntimeInspection(['configuration' => new RuntimeCheck(ProbeState::Failed, 'BACKGROUND_CONFIGURATION_INVALID')], true);
        }
        $checks = [];
        foreach (['scheduler', ...$settings->queues] as $component) {
            try {
                $checks['activity_'.$component] = $this->heartbeats->read($settings, $component);
            } catch (Throwable) {
                $checks['activity_'.$component] = new RuntimeCheck(ProbeState::Failed, 'BACKGROUND_HEARTBEAT_READ_FAILED');
            }
        }
        foreach (BackgroundWorkloads::SOURCES as $source) {
            try {
                $checks[$source] = $this->workloads->inspect($source, $settings);
            } catch (Throwable) {
                $checks[$source] = new RuntimeCheck(ProbeState::Failed, 'BACKGROUND_WORK_READ_FAILED');
            }
        }

        return new RuntimeInspection($checks, true);
    }

    /** @return array<string, mixed> */
    public function report(RuntimeInspection $inspection): array
    {
        $alerts = [];
        $namespace = $this->config->get('operations.resource_namespace');
        $scope = is_string($namespace) ? $namespace : '';
        foreach ($inspection->checks as $component => $check) {
            if (! in_array($check->state, [ProbeState::Warning, ProbeState::Failed], true)) {
                continue;
            }
            $alerts[] = [
                'component' => $component, 'code' => $check->code,
                'severity' => $check->state === ProbeState::Failed ? 'error' : 'warning',
                'deduplication_key' => hash('sha256', $scope.':'.$component.':'.$check->code),
                'responsible_role' => 'infrastructure_operator_to_designate',
                'procedure' => 'OPS-003-BG', 'delivery' => 'prepared_only',
            ];
        }

        return [...$inspection->toArray(), 'alert_candidates' => $alerts];
    }
}
