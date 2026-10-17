<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Models\Availability;
use App\Modules\Scheduling\Domain\Models\AvailabilityRevision;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface AvailabilityRepository
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,Availability>
     */
    public function paginate(string $org, ?string $personId, CarbonImmutable $from, CarbonImmutable $to, array $filters): LengthAwarePaginator;

    public function find(string $org, string $id, ?string $personId, bool $lock = false): Availability;

    public function overlaps(string $org, string $personId, CarbonImmutable $from, CarbonImmutable $to, ?string $except): bool;

    /** @param array<string,mixed> $attributes */
    public function save(?Availability $record, array $attributes, int $actorId, string $action): Availability;

    /** @return LengthAwarePaginator<int,AvailabilityRevision> */
    public function history(Availability $record, int $page, int $perPage): LengthAwarePaginator;
}
