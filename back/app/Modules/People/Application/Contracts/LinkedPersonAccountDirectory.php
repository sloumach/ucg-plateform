<?php

namespace App\Modules\People\Application\Contracts;

/** Account linkage only. Callers must independently authorize the account's access to their own resources. */
interface LinkedPersonAccountDirectory
{
    public function accountId(string $organizationId, string $personId): ?int;

    public function personId(string $organizationId, int $accountId): ?string;
}
