<?php

namespace App\Support\Audit;

use App\Support\Data\DataTransferObject;

final readonly class AuditEntry extends DataTransferObject
{
    /**
     * @param  array<string, bool|int|string|list<string>|null>  $before
     * @param  array<string, bool|int|string|list<string>|null>  $after
     */
    public function __construct(public string $id, public string $source, public ?string $organizationId,
        public ?int $actorUserId, public string $action, public string $targetType, public ?string $targetId,
        public array $before, public array $after, public string $reasonCode, public string $requestId,
        public ?string $ipAddress, public string $occurredAt, public string $databaseTime) {}
}
