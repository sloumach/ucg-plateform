<?php

namespace App\Console\Commands;

use App\Support\Operations\RuntimeCheck;
use App\Support\Operations\RuntimeDiagnostics;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:inspect-runtime {--probe : Autoriser les lectures des dépendances} {--json : Afficher le rapport JSON}')]
#[Description('Inspecter les dépendances sans écriture ni action corrective (accès serveur)')]
class InspectRuntime extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RuntimeDiagnostics $diagnostics): int
    {
        $inspection = $diagnostics->inspect((bool) $this->option('probe'));
        if ($this->option('json')) {
            $this->line(json_encode($inspection->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Contrôles de lecture partiels ; supervision non validée.');
            $this->table(['Composant', 'État', 'Code'], array_map(
                fn (string $name, RuntimeCheck $check): array => [$name, $check->state->value, $check->code],
                array_keys($inspection->checks), array_values($inspection->checks),
            ));
            $this->line('Procédures : docs/procedures-incident-support.md (OPS-003).');
        }

        return $inspection->exitCode();
    }
}
