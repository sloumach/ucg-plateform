<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentPersonRepository implements PersonRepository
{
    public function hasDeparted(string $organizationId, string $personId): bool
    {
        return PersonDeparture::query()->forPerson($organizationId, $personId)->exists();
    }

    public function paginate(string $organizationId, array $filters, bool $sports, bool $administrative): LengthAwarePaginator
    {
        $columns = ['people.id', 'people.organization_id', 'people.version'];
        if ($sports) {
            $columns = [...$columns, 'people.pseudonym', 'people.language', 'people.timezone', 'people.status'];
        }
        if ($administrative) {
            $columns = [...$columns, 'people.user_id', 'people.first_name', 'people.last_name', 'people.contact_email'];
        }
        $query = Person::query()->forOrganization($organizationId)->select($columns);
        if ($sports) {
            $query->withCount('gameProfiles');
        }
        foreach (['status', 'language'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['q'])) {
            // Escape wildcards explicitly on both supported engines. Search is a literal prefix.
            $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $filters['q']);
            $query->whereRaw("LOWER(pseudonym) LIKE LOWER(?) ESCAPE '!'", [$prefix.'%']);
        }
        if (isset($filters['game_key']) || isset($filters['platform_key'])) {
            $query->whereHas('gameProfiles', function ($profiles) use ($organizationId, $filters): void {
                $profiles->where('organization_id', $organizationId)->where('status', 'active');
                foreach (['game_key', 'platform_key'] as $column) {
                    if (isset($filters[$column])) {
                        $profiles->where($column, $filters[$column]);
                    }
                }
            });
        }
        if (isset($filters['account_linked'])) {
            $filters['account_linked'] === 'linked' ? $query->whereNotNull('user_id') : $query->whereNull('user_id');
        }
        $sort = match ($filters['sort'] ?? 'id') {
            'pseudonym' => 'pseudonym',
            'status' => 'status',
            default => 'id',
        };
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $query->orderBy('people.'.$sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('people.id', $direction);
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1))->appends($filters);
    }

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
