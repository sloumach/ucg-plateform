<?php

namespace App\Modules\People\Infrastructure\Persistence;

use App\Modules\People\Domain\Contracts\PersonLifecycleRepository;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

final class EloquentPersonLifecycleRepository implements PersonLifecycleRepository
{
    public function periods(string $organizationId, string $personId, int $perPage, int $page): LengthAwarePaginator
    {
        return PersonPeriod::query()->forPerson($organizationId, $personId)->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function findPeriod(string $organizationId, string $personId, string $id): PersonPeriod
    {
        return PersonPeriod::query()->forPerson($organizationId, $personId)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function savePeriod(string $organizationId, string $personId, ?PersonPeriod $period, array $attributes): PersonPeriod
    {
        $period ??= new PersonPeriod;
        $period->forceFill([...$attributes, 'organization_id' => $organizationId, 'person_id' => $personId])->save();

        return $period;
    }

    public function overlaps(string $organizationId, string $personId, string $kind, ?string $role, CarbonImmutable $start, ?CarbonImmutable $end, ?string $except): bool
    {
        return PersonPeriod::query()->forPerson($organizationId, $personId)->where('kind', $kind)->where('role_key', $role)->where('status', '!=', 'cancelled')
            ->when($except !== null, fn ($query) => $query->where('id', '!=', $except))
            ->when($end !== null, fn ($query) => $query->where('starts_at', '<', $end))
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $start))->exists();
    }

    public function activePeriods(string $organizationId, string $personId): iterable
    {
        return PersonPeriod::query()->forPerson($organizationId, $personId)->where('status', 'active')->lazyById(100);
    }

    public function unavailableNow(string $organizationId, string $personId, CarbonImmutable $at): bool
    {
        return PersonPeriod::query()->forPerson($organizationId, $personId)->where('kind', 'unavailability')->where('status', 'active')->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $at))->exists();
    }

    public function periodCount(string $organizationId, string $personId): int
    {
        return PersonPeriod::query()->forPerson($organizationId, $personId)->count();
    }

    public function items(string $organizationId, string $personId, int $perPage, int $page): LengthAwarePaginator
    {
        return PersonDepartureItem::query()->forPerson($organizationId, $personId)->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function findItem(string $organizationId, string $personId, string $id): PersonDepartureItem
    {
        return PersonDepartureItem::query()->forPerson($organizationId, $personId)->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function saveItem(string $organizationId, string $personId, ?PersonDepartureItem $item, array $attributes): PersonDepartureItem
    {
        $item ??= new PersonDepartureItem;
        $item->forceFill([...$attributes, 'organization_id' => $organizationId, 'person_id' => $personId])->save();

        return $item;
    }

    public function itemCount(string $organizationId, string $personId, bool $openOnly = false): int
    {
        return PersonDepartureItem::query()->forPerson($organizationId, $personId)->when($openOnly, fn ($query) => $query->where('status', 'open'))->count();
    }

    public function departure(string $organizationId, string $personId): ?PersonDeparture
    {
        return PersonDeparture::query()->forPerson($organizationId, $personId)->first();
    }

    public function createDeparture(string $organizationId, string $personId, array $attributes): PersonDeparture
    {
        $departure = new PersonDeparture;
        $departure->forceFill([...$attributes, 'organization_id' => $organizationId, 'person_id' => $personId])->save();

        return $departure;
    }
}
