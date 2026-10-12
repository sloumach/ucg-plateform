<?php

namespace App\Modules\People\Domain\Contracts;

use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Domain\Models\PersonPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

interface PersonLifecycleRepository
{
    /** @return LengthAwarePaginator<int,PersonPeriod> */
    public function periods(string $organizationId, string $personId, int $perPage, int $page): LengthAwarePaginator;

    public function findPeriod(string $organizationId, string $personId, string $id): PersonPeriod;

    /** @param array<string,mixed> $attributes */
    public function savePeriod(string $organizationId, string $personId, ?PersonPeriod $period, array $attributes): PersonPeriod;

    public function overlaps(string $organizationId, string $personId, string $kind, ?string $role, CarbonImmutable $start, ?CarbonImmutable $end, ?string $except): bool;

    /** @return iterable<PersonPeriod> */
    public function activePeriods(string $organizationId, string $personId): iterable;

    public function unavailableNow(string $organizationId, string $personId, CarbonImmutable $at): bool;

    public function periodCount(string $organizationId, string $personId): int;

    /** @return LengthAwarePaginator<int,PersonDepartureItem> */
    public function items(string $organizationId, string $personId, int $perPage, int $page): LengthAwarePaginator;

    public function findItem(string $organizationId, string $personId, string $id): PersonDepartureItem;

    /** @param array<string,mixed> $attributes */
    public function saveItem(string $organizationId, string $personId, ?PersonDepartureItem $item, array $attributes): PersonDepartureItem;

    public function itemCount(string $organizationId, string $personId, bool $openOnly = false): int;

    public function departure(string $organizationId, string $personId): ?PersonDeparture;

    /** @param array<string,mixed> $attributes */
    public function createDeparture(string $organizationId, string $personId, array $attributes): PersonDeparture;
}
