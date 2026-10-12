<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\Person;
use Illuminate\Pagination\LengthAwarePaginator;

interface PersonRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Person>
     */
    public function paginate(string $organizationId, array $filters, bool $sports, bool $administrative): LengthAwarePaginator;

    public function find(string $organizationId, string $personId, bool $lock = false): Person;

    /** @param array<string, mixed> $attributes */
    public function create(string $organizationId, array $attributes): Person;

    /** @param array<string, mixed> $attributes */
    public function update(Person $person, array $attributes): Person;
}
