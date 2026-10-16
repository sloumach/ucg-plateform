<?php

namespace App\Modules\Teams\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CommunicationTeamsRepository
{
    /** @return array{teams: list<string>, games: list<string>} */
    public function current(string $org, ?string $personId): array;

    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function choices(string $org, string $type, int $page, int $perPage): LengthAwarePaginator;

    public function assertActive(string $org, string $type, string $id): void;
}
