<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class InvitationData extends DataTransferObject
{
    /** @param list<string> $roles */
    public function __construct(public string $email, public array $roles, public ?string $startsAt = null, public ?string $endsAt = null) {}
}
