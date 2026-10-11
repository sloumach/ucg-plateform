<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\Person;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

final class EloquentPersonRepository implements PersonRepository
{
    public function find(string $organizationId, string $personId, bool $lock = false): Person
    {
        if (! Str::isUuid($organizationId) || ! Str::isUuid($personId)) {
            throw new ModelNotFoundException;
        }

        return Person::query()->forOrganization($organizationId)->when($lock, fn ($query) => $query->lockForUpdate())
            ->whereKey(strtolower($personId))->firstOrFail();
    }

    public function create(string $organizationId, array $attributes): Person
    {
        $person = new Person;
        $person->forceFill([...$attributes, 'organization_id' => strtolower($organizationId)])->save();

        return $person;
    }

    public function update(Person $person, array $attributes): Person
    {
        $person->forceFill($attributes)->save();

        return $person;
    }
}
