<?php

namespace App\Modules\Scheduling\Domain\Contracts;

use App\Modules\Scheduling\Domain\Models\CalendarResource;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CalendarResourceRepository
{
    /** @return LengthAwarePaginator<int,CalendarResource> */
    public function paginate(string $org, int $page, int $perPage, bool $archived): LengthAwarePaginator;

    public function find(string $org, string $id, bool $lock = false): CalendarResource;

    /** @param array<string,mixed> $attributes */
    public function save(?CalendarResource $record, array $attributes): CalendarResource;

    /** @param list<string> $ids */
    public function assertAssignable(string $org, array $ids): void;
}
