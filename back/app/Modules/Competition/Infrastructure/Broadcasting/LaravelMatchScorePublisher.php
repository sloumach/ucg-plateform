<?php

namespace App\Modules\Competition\Infrastructure\Broadcasting;

use App\Modules\Competition\Application\Contracts\MatchScorePublisher;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class LaravelMatchScorePublisher implements MatchScorePublisher
{
    public function __construct(private Connection $database, private Dispatcher $events, private LoggerInterface $logger) {}

    public function changed(string $organizationId, string $matchId): void
    {
        $event = new MatchScoreChanged($organizationId, $matchId, (string) Str::uuid(), now()->utc()->format('Y-m-d\TH:i:s.u\Z'));
        $this->database->afterCommit(function () use ($event): void {
            try {
                $this->events->dispatch($event);
            } catch (Throwable $exception) {
                // A transport outage must never report an already committed sporting action as failed.
                $this->logger->warning('match.score.broadcast_failed', ['tenant_id' => $event->organizationId, 'match_id' => $event->matchId, 'event_id' => $event->eventId, 'exception_type' => $exception::class]);
            }
        });
    }
}
