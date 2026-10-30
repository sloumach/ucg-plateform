<?php

namespace App\Console\Commands;

use App\Support\Operations\AlertService;
use App\Support\Operations\SupervisionDiagnostics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('ops:inspect-supervision {--probe} {--external} {--record-alerts} {--json}')]
#[Description('Diagnostic serveur agrégé, sondes externes et registre d’alertes sur options explicites')]
class InspectSupervision extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SupervisionDiagnostics $diagnostics, AlertService $alerts): int
    {
        if ($this->option('record-alerts') && ! $this->option('probe')) {
            $this->line('{"code":"ALERT_PROBE_REQUIRED","alert_dispatched":false}');

            return 2;
        }
        $inspection = $diagnostics->inspect((bool) $this->option('probe'), (bool) $this->option('external'));
        $report = $inspection->toArray();
        $report['alerts_recorded'] = false;
        if ($this->option('record-alerts')) {
            try {
                $alerts->synchronize($inspection);
                $report['alerts_recorded'] = true;
            } catch (Throwable) {
                $this->line('{"code":"ALERT_LEDGER_WRITE_FAILED","alert_dispatched":false}');

                return 1;
            }
        }
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Contrôles partiels ; aucun message externe envoyé.');
            foreach ($inspection->checks as $name => $check) {
                $this->line($name.': '.$check->state->value.' / '.$check->code);
            }
            $this->line('Procédure OPS-003-FINAL : docs/procedures-incident-support.md.');
        }

        return $inspection->exitCode();
    }
}
