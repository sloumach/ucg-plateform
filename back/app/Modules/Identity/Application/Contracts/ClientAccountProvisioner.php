<?php

namespace App\Modules\Identity\Application\Contracts;

interface ClientAccountProvisioner
{
    public function emailExists(string $email): bool;

    /** Called only after the recipient proves possession of the activation link, within the organization transaction. */
    public function createVerified(string $name, string $email, string $password, string $requestId): int;
}
