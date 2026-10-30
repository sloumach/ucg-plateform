<?php

namespace App\Support\Operations;

use App\Contracts\Operations\ApiMetrics;
use Illuminate\Contracts\Config\Repository;
use Throwable;

final readonly class SupervisionDiagnostics
{
    public function __construct(private RuntimeDiagnostics $runtime, private BackgroundMonitor $background,
        private ApiMetrics $api, private DependencyDiagnostics $dependencies, private Repository $config,
        private BackupDiagnostics $backups) {}

    public function inspect(bool $probe, bool $external): RuntimeInspection
    {
        if (! $probe) {
            return new RuntimeInspection(['supervision' => new RuntimeCheck(ProbeState::Unverified, 'PROBE_NOT_REQUESTED')], false);
        }
        $checks = [];
        foreach (['runtime' => $this->runtime->inspect(true), 'background' => $this->background->inspect(true)] as $scope => $inspection) {
            foreach ($inspection->checks as $name => $check) {
                if ($scope === 'runtime' && $name === 'private_storage') {
                    continue;
                }
                $checks[$scope.'_'.$name] = $check;
            }
        }
        try {
            $checks['api_http'] = $this->config->get('operations.api.enabled') === true ? $this->api->inspect()
                : new RuntimeCheck(ProbeState::Unverified, 'API_TELEMETRY_DISABLED');
        } catch (Throwable) {
            $checks['api_http'] = new RuntimeCheck(ProbeState::Failed, 'API_METRICS_READ_FAILED');
        }

        return new RuntimeInspection([...$checks, ...$this->dependencies->inspect($external),
            'backup_recovery' => $this->backups->inspect(true)], true);
    }
}
