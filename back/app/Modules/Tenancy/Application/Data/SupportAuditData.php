<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class SupportAuditData extends DataTransferObject
{
    public function __construct(public int $actorUserId, public string $requestId, public ?string $ipAddress = null) {}
}
