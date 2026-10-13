<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\Team;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TeamRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, Team>
     */
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator;

    public function find(string $organizationId, string $id, bool $lock = false): Team;

    public function count(string $organizationId): int;

    /** @param array<string, mixed> $attributes */
    public function save(?Team $team, array $attributes): Team;
}
