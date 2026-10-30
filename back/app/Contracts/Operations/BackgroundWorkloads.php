<?php

namespace App\Contracts\Operations;

use App\Support\Operations\BackgroundSettings;
use App\Support\Operations\RuntimeCheck;

interface BackgroundWorkloads
{
    public const SOURCES = ['person_imports', 'statistical_imports', 'contract_reminders', 'contract_sweeps', 'competition_sweeps'];

    public function inspect(string $source, BackgroundSettings $settings): RuntimeCheck;
}
