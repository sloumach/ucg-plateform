<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Teams\Domain\Models\TeamMovement;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TeamMembershipRepository
{
    /** @param array<string,mixed> $filters
     * @return LengthAwarePaginator<int,TeamMembership>
     */
    public function paginate(string $org, string $teamId, array $filters): LengthAwarePaginator;

    /** @return LengthAwarePaginator<int,TeamMovement> */
    public function movements(string $org, string $teamId, int $perPage, int $page): LengthAwarePaginator;

    public function find(string $org, string $teamId, string $id): TeamMembership;

    public function count(string $org, string $personId): int;

    public function hasLive(string $org, string $teamId, CarbonImmutable $at): bool;

    public function overlaps(string $org, string $teamId, string $personId, string $role, CarbonImmutable $start, ?CarbonImmutable $end): bool;

    /** @param array<string,mixed> $values */
    public function save(?TeamMembership $membership, array $values): TeamMembership;

    /** @param array<string,mixed> $values */
    public function record(array $values): TeamMovement;

    /** @return iterable<TeamMembership> */
    public function openForPerson(string $org, string $personId, CarbonImmutable $at): iterable;

    /** @return list<TeamMembership> */
    public function effectiveForAccount(string $org, string $teamId, string $personId, CarbonImmutable $at): array;
}
