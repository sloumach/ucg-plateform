<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TeamRepository
{
    /** @return LengthAwarePaginator<int, array{id: string, label: string}> */
    public function partnershipPage(string $org, int $page, int $perPage): LengthAwarePaginator;

    /** @param list<string> $ids
     * @return array<string, string>
     */
    public function partnershipLabels(string $org, array $ids): array;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator;

    public function find(string $organizationId, string $id, bool $lock = false): Team;

    public function count(string $organizationId): int;

    /** @param array<string, mixed> $attributes */
    public function save(?Team $team, array $attributes): Team;
}
