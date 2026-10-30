<?php

namespace App\Contracts\Operations;

use App\Support\Operations\ApiMeasurement;
use App\Support\Operations\RuntimeCheck;

interface ApiMetrics
{
    public function record(ApiMeasurement $measurement): bool;

    public function inspect(): RuntimeCheck;
}
