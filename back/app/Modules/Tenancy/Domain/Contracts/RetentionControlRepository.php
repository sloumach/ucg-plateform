<?php

namespace App\Modules\Tenancy\Domain\Contracts;

interface RetentionControlRepository
{
    /** @return array{version: int, holds: list<string>, restricted_archive: bool, quarantine: ?string} */
    public function state(string $organizationId): array;

    /** @return array<string, mixed>|null */
    public function request(string $organizationId, string $requestId): ?array;

    /** @param array{version: int, holds: list<string>, restricted_archive: bool, quarantine: ?string} $state
     * @param  array<string, mixed>|null  $evidence
     */
    public function append(string $organizationId, int $actorId, string $requestId, string $reason, string $action, string $reference, array $state, ?array $evidence = null): void;
}
