<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\Person;

interface PersonRepository
{
    public function find(string $organizationId, string $personId, bool $lock = false): Person;

    /** @param array<string, mixed> $attributes */
    public function create(string $organizationId, array $attributes): Person;

    /** @param array<string, mixed> $attributes */
    public function update(Person $person, array $attributes): Person;
}
