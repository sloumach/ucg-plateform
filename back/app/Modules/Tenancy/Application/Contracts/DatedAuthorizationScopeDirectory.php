<?php

namespace App\Modules\Tenancy\Application\Contracts;

use Carbon\CarbonImmutable;

interface DatedAuthorizationScopeDirectory extends AuthorizationScopeDirectory
{
    /** Earliest start of a current membership covering the requested window; null denies scoped grants. */
    public function periodStart(string $org, string $type, string $id, int $userId, CarbonImmutable $at, ?CarbonImmutable $end = null, bool $cover = false): ?CarbonImmutable;

    public function bindAssignment(string $org, string $type, string $id, int $userId, string $assignmentId, CarbonImmutable $start, ?CarbonImmutable $end): void;

    /** @return list<string> */
    public function assignmentIds(string $org, string $type, string $id, int $userId, CarbonImmutable $at): array;
}
