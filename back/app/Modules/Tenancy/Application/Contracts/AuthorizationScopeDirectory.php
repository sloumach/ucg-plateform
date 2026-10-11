<?php

namespace App\Modules\Tenancy\Application\Contracts;

/** Owning modules bind this contract after they can verify actual tenant resources. */
interface AuthorizationScopeDirectory
{
    public function exists(string $organizationId, string $type, string $id): bool;

    /** @return list<string> */
    public function availableTypes(): array;
}
