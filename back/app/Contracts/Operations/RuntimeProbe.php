<?php

namespace App\Contracts\Operations;

use App\Support\Operations\RuntimeCheck;

interface RuntimeProbe
{
    public function database(): RuntimeCheck;

    public function cache(): RuntimeCheck;

    public function queues(int $sampleLimit, int $warningSeconds): RuntimeCheck;

    public function failedJobs(int $sampleLimit): RuntimeCheck;

    public function privateStorage(): RuntimeCheck;
}
