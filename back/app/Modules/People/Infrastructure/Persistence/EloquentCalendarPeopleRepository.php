<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\CalendarPeopleRepository;
use App\Modules\People\Domain\Models\Person;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as Query;

final readonly class EloquentCalendarPeopleRepository implements CalendarPeopleRepository
{
    public function accounts(string $org, array $ids): array
    {
        $result = [];
        foreach ($this->active($org)->whereIn('id', $ids)->whereNotNull('user_id')->get(['id', 'user_id']) as $person) {
            $result[$person->id] = (int) $person->user_id;
        }

        return $result;
    }

    public function viewer(string $org, int $userId): array
    {
        $person = $this->active($org)->where('user_id', $userId)->first(['id', 'timezone']);

        return ['person_id' => $person?->id, 'timezone' => $person?->timezone];
    }

    public function choices(string $org, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->active($org)->orderBy('pseudonym')->orderBy('id')->paginate($perPage, ['id', 'pseudonym'], 'page', $page)
            ->through(fn (Person $person): array => ['id' => $person->id, 'label' => $person->pseudonym]);
    }

    public function assertAssignable(string $org, array $ids): void
    {
        $found = $this->active($org)->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id'])->all();
        if (count($found) !== count($ids)) {
            throw new ModelNotFoundException;
        }
    }

    public function labels(string $org, array $ids): array
    {
        $labels = [];
        foreach ($this->active($org)->whereIn('id', $ids)->get(['id', 'pseudonym']) as $person) {
            $labels[$person->id] = $person->pseudonym;
        }

        return $labels;
    }

    /** @return Builder<Person> */
    private function active(string $org): Builder
    {
        return Person::query()->forOrganization($org)->where('status', '!=', 'former')->whereNotExists(fn (Query $q) => $q
            ->selectRaw('1')->from('person_departures')->whereColumn('person_departures.organization_id', 'people.organization_id')
            ->whereColumn('person_departures.person_id', 'people.id'));
    }
}
