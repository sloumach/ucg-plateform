<?php

namespace App\Contracts\Operations;

use App\Support\Operations\BackgroundSettings;
use App\Support\Operations\RuntimeCheck;

interface BackgroundHeartbeats
{
    public function record(BackgroundSettings $settings, string $component): void;

    public function read(BackgroundSettings $settings, string $component): RuntimeCheck;
}
