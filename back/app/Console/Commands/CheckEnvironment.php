<?php

namespace App\Console\Commands;

use App\Support\EnvironmentReadiness;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ops:check-environment {--expect= : staging ou production} {--json : Rapport sans valeurs de configuration}')]
#[Description('Contrôler la configuration sans connexion aux ressources ni modification')]
class CheckEnvironment extends Command
{
    public function handle(EnvironmentReadiness $readiness): int
    {
        $expected = $this->option('expect');
        if (! is_string($expected) || ! in_array($expected, ['staging', 'production'], true)) {
            $this->error('Préciser --expect=staging ou --expect=production.');

            return self::INVALID;
        }

        $failures = $readiness->failures($expected);
        if ($this->option('json')) {
            $this->line(json_encode([
                'configuration_valid' => $failures === [],
                'failures' => $failures,
                'scope' => 'configuration_only',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($failures as $failure) {
                $this->error($failure);
            }
            $this->line($failures === [] ? 'Configuration conforme aux contrôles OPS-001.' : 'Configuration à compléter.');
            $this->line('Contrôle local uniquement : isolation réelle, accès et provenance des données à vérifier sur infrastructure.');
        }

        return $failures === [] ? self::SUCCESS : self::FAILURE;
    }
}
