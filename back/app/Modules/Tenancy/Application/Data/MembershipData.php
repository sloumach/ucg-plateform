<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Support\Data\DataTransferObject;

final readonly class MembershipData extends DataTransferObject
{
    /** @param list<string> $roles */
    public function __construct(public MembershipStatus $status, public array $roles, public string $startsAt, public ?string $endsAt = null) {}
}
