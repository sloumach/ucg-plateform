<?php

namespace App\Modules\Tenancy\Domain\Data;

final readonly class PortabilityOperation
{
    /** @param array<string, mixed> $details */
    public function __construct(
        public string $id, public string $organizationId, public int $actorUserId,
        public string $requestId, public string $kind, public string $status,
        public string $reason, public ?string $disk, public ?string $expiresAt,
        public int $bytes, public ?string $sha256, public array $details,
    ) {}
}
