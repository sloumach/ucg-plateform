<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Domain\Models\TeamMembership;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TeamLineupRepository
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamLineup>
     */
    public function paginate(string $org, string $teamId, array $filters): LengthAwarePaginator;

    public function find(string $org, string $teamId, string $id, bool $lock = false): TeamLineup;

    public function count(string $org, string $teamId): int;

    /** @param list<string> $ids
     * @return array<string,TeamMembership>
     */
    public function memberships(string $org, string $teamId, array $ids): array;

    /** @param array<string,mixed> $values */
    public function save(?TeamLineup $lineup, array $values): TeamLineup;

    /** @param list<array<string,mixed>> $entries */
    public function replaceEntries(TeamLineup $lineup, array $entries): void;
}
