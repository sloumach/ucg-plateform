<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class OrganizationAuditData extends DataTransferObject
{
    public function __construct(public int $actorUserId, public string $reason, public string $requestId) {}
}
