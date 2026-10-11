<?php

namespace App\Support\Audit;

use App\Support\Data\DataTransferObject;

final readonly class AuditFilter extends DataTransferObject
{
    public function __construct(public int $auditorId, public int $perPage = 20, public ?string $cursor = null,
        public ?string $source = null, public ?string $action = null, public ?int $actorId = null,
        public ?string $from = null, public ?string $until = null) {}

    public function scope(?string $organizationId): string
    {
        return hash('sha256', json_encode([$this->auditorId, $organizationId, $this->source, $this->action, $this->actorId, $this->from, $this->until], JSON_THROW_ON_ERROR));
    }
}
