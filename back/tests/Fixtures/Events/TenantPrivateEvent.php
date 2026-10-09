<?php

namespace Tests\Fixtures\Events;

use App\Support\Queue\JobContext;
use App\Support\Queue\QueueName;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use LogicException;

final class TenantPrivateEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public readonly string $tenantId;

    public function __construct(
        public readonly JobContext $context,
        public readonly int $userId,
    ) {
        if ($this->context->tenantId === null) {
            throw new LogicException('A tenant event requires a tenant identifier.');
        }

        $this->tenantId = $this->context->tenantId;
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('users.'.$this->userId)];
    }

    public function broadcastQueue(): string
    {
        return QueueName::Broadcasts->value;
    }

    public function broadcastAs(): string
    {
        return 'tenant.infrastructure.verified';
    }

    /** @return array{tenant_id: string, request_id: string} */
    public function broadcastWith(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'request_id' => $this->context->requestId,
        ];
    }
}
