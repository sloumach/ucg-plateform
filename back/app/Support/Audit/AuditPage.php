<?php

namespace App\Support\Audit;

use App\Support\Data\DataTransferObject;

final readonly class AuditPage extends DataTransferObject
{
    /** @param list<AuditEntry> $entries */
    public function __construct(public array $entries, public ?string $nextCursor, public int $perPage) {}
}
