<?php

namespace App\Modules\Competition\Application\Contracts;

use App\Support\Queue\JobContext;

interface MatchRecalculationQueue
{
    public function dispatch(JobContext $context, string $match, string $correction): void;
}
