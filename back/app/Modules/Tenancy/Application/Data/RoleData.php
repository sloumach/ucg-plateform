<?php

namespace App\Modules\Tenancy\Application\Data;

use App\Support\Data\DataTransferObject;

final readonly class RoleData extends DataTransferObject
{
    /** @param list<string> $permissions */
    public function __construct(public string $key, public string $name, public array $permissions, public bool $active, public ?string $version = null) {}

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'permissions' => $this->permissions, 'active' => $this->active];
    }
}
