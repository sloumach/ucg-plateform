<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Data\DataTransferObject;

final readonly class ActiveOrganizationData extends DataTransferObject
{
    public function __construct(public ?TenantContext $context, public ?string $revision = null, public bool $confirmed = false) {}
}
