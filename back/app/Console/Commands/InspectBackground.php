<?php

namespace App\Console\Commands;

use App\Support\Operations\BackgroundMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:inspect-background {--probe : Autoriser les lectures opérationnelles} {--json : Afficher le rapport JSON}')]
#[Description('Examiner activité workers/scheduler et métadonnées des imports/rappels (accès serveur)')]
class InspectBackground extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BackgroundMonitor $monitor): int
    {
        $inspection = $monitor->inspect((bool) $this->option('probe'));
        $report = $monitor->report($inspection);
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Diagnostic partiel ; alertes préparées uniquement, supervision non validée.');
            foreach ($inspection->checks as $component => $check) {
                $this->line($component.': '.$check->state->value.' / '.$check->code);
            }
            $this->line('Procédure OPS-003-BG : docs/procedures-incident-support.md.');
        }

        return $inspection->exitCode();
    }
}
