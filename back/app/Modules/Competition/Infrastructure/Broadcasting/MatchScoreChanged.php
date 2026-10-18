<?php

namespace App\Modules\Competition\Infrastructure\Broadcasting;

use App\Support\Queue\QueueName;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** Invalidation only: connected clients must reauthorize every read through the API. */
final readonly class MatchScoreChanged implements ShouldBroadcast
{
    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff;

    public function __construct(public string $organizationId, public string $matchId, public string $eventId, public string $changedAt)
    {
        $this->tries = 3;
        $this->timeout = 30;
        $this->backoff = [1, 5, 15];
    }

    public function broadcastWhen(): bool
    {
        // Disabled realtime must not fill durable queues with null broadcasts.
        return ! in_array(config('broadcasting.default'), [null, 'null'], true);
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('tenants.'.$this->organizationId.'.matches.'.$this->matchId)];
    }

    public function broadcastAs(): string
    {
        return 'match.score.changed';
    }

    public function broadcastQueue(): string
    {
        return QueueName::Broadcasts->value;
    }

    /** @return array{event_id:string,organization_id:string,match_id:string,changed_at:string} */
    public function broadcastWith(): array
    {
        return ['event_id' => $this->eventId, 'organization_id' => $this->organizationId, 'match_id' => $this->matchId, 'changed_at' => $this->changedAt];
    }
}
