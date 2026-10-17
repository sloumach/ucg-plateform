<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\PersonRepository;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Domain\Models\PersonDeparture;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

final class EloquentPersonRepository implements PersonRepository
{
    public function sportingSnapshots(string $org, array $ids): array
    {
        $records = Person::query()->forOrganization($org)->whereIn('id', $ids)->whereIn('status', ['active', 'substitute'])
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('person_departures')
                ->whereColumn('person_departures.organization_id', 'people.organization_id')->whereColumn('person_departures.person_id', 'people.id'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'pseudonym', 'version']);
        $result = [];
        foreach ($records as $record) {
            $result[$record->id] = ['label' => $record->pseudonym, 'version' => $record->version];
        }
        if (count($result) !== count($ids)) {
            throw (new ModelNotFoundException)->setModel(Person::class);
        }

        return $result;
    }

    public function partnershipPage(string $org, int $page, int $perPage): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Person::query()->where('organization_id', $org)->whereIn('status', ['active', 'substitute'])->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('person_departures')->whereColumn('person_departures.organization_id', 'people.organization_id')->whereColumn('person_departures.person_id', 'people.id');
        })->select(['id', 'pseudonym'])->orderBy('pseudonym')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page)->through(fn (Person $record): array => ['id' => $record->id, 'label' => $record->pseudonym]);
    }

    public function partnershipLabels(string $org, array $ids): array
    {
        return Person::query()->where('organization_id', $org)->whereIn('status', ['active', 'substitute'])->whereNotExists(function (Builder $query): void {
            $query->selectRaw('1')->from('person_departures')->whereColumn('person_departures.organization_id', 'people.organization_id')->whereColumn('person_departures.person_id', 'people.id');
        })->whereIn('id', $ids)->pluck('pseudonym', 'id')->all();
    }

    public function linkedAccount(string $organizationId, string $personId): ?int
    {
        $id = Person::query()->forOrganization($organizationId)->whereKey(strtolower($personId))
            ->where('status', '!=', 'former')->value('user_id');

        return $id === null ? null : (int) $id;
    }

    public function contractPeople(string $organizationId, array $ids): array
    {
        return array_values(Person::query()->forOrganization($organizationId)->whereIn('id', $ids)
            ->select(['id', 'organization_id', 'first_name', 'last_name'])->orderBy('id')->lockForUpdate()->get()->all());
    }

    public function contractPeoplePage(string $organizationId, int $page, int $perPage): LengthAwarePaginator
    {
        return Person::query()->forOrganization($organizationId)
            ->select(['id', 'organization_id', 'first_name', 'last_name'])->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function eligibilitySubjects(string $org, array $ids, string $gameKey, ?string $platform, bool $birth): array
    {
        $query = Person::query()->forOrganization($org)->whereIn('id', $ids)->select(['id', 'organization_id', 'version', ...($birth ? ['birth_date'] : [])]);
        if ($platform !== null) {
            // Two matches per person are enough to detect ambiguity, without loading every profile.
            $query->with(['gameProfiles' => fn ($profiles) => $profiles->where('organization_id', $org)->where('game_key', $gameKey)
                ->where('platform_key', $platform)->where('status', 'active')->orderBy('id')->limit(2)]);
        }

        return array_values($query->get()->all());
    }

    public function pseudonyms(string $org, array $ids): array
    {
        return Person::query()->forOrganization($org)->whereIn('id', $ids)->pluck('pseudonym', 'id')->all();
    }

    public function personForAccount(string $org, int $userId): ?string
    {
        return Person::query()->forOrganization($org)->where('user_id', $userId)->where('status', '!=', 'former')->value('id');
    }

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
