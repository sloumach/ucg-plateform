<?php

namespace App\Modules\People\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CalendarPeopleRepository
{
    /** @param list<string> $ids
     * @return array<string,int>
     */
    public function accounts(string $org, array $ids): array;

    /** @return array{person_id: string|null, timezone: string|null} */
    public function viewer(string $org, int $userId): array;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(string $org, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids */
    public function assertAssignable(string $org, array $ids): void;

    /** @param list<string> $ids
     * @return array<string,string>
     */
    public function labels(string $org, array $ids): array;
}
