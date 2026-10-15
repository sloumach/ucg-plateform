<?php

namespace App\Modules\People\Application\Services;

use App\Modules\People\Application\Contracts\LinkedPersonAccountDirectory;
use App\Modules\People\Domain\Contracts\PersonRepository;

final readonly class LinkedPersonAccountService implements LinkedPersonAccountDirectory
{
    public function __construct(private PersonRepository $people) {}

    public function accountId(string $organizationId, string $personId): ?int
    {
        return $this->people->linkedAccount($organizationId, $personId);
    }

    public function personId(string $organizationId, int $accountId): ?string
    {
        return $this->people->personForAccount($organizationId, $accountId);
    }
}
