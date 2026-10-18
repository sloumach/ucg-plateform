<?php

namespace App\Modules\Competition\Infrastructure\Queue;

use App\Modules\Competition\Application\Contracts\MatchRecalculationQueue;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;

final readonly class LaravelMatchRecalculationQueue implements MatchRecalculationQueue
{
    public function __construct(private Dispatcher $bus) {}

    public function dispatch(JobContext $context, string $match, string $correction): void
    {
        $this->bus->dispatch(new RecalculateMatchScore($context, $match, $correction));
    }
}
