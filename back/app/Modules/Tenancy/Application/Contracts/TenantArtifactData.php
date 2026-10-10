<?php

namespace App\Modules\Tenancy\Application\Contracts;

use App\Support\Data\DataTransferObject;

final readonly class TenantArtifactData extends DataTransferObject
{
    public function __construct(public string $id, public string $name, public int $bytes) {}
}
