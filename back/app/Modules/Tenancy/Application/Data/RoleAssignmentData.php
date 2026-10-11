<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class RoleAssignmentData extends DataTransferObject
{
    public function __construct(public string $roleId, public int $userId, public string $scopeType, public string $scopeId,
        public string $status, public string $startsAt, public ?string $endsAt, public ?string $version = null) {}

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['role_id' => $this->roleId, 'user_id' => $this->userId, 'scope_type' => $this->scopeType,
            'scope_id' => $this->scopeId, 'status' => $this->status, 'starts_at' => $this->startsAt, 'ends_at' => $this->endsAt];
    }
}
