<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use App\Modules\Tenancy\Domain\SupportAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SupportAccessRepository
{
    /** @return LengthAwarePaginator<int, OrganizationSupportAccess> */
    public function paginate(int $actorId, int $perPage, int $page): LengthAwarePaginator;

    public function activeCount(string $organizationId, int $actorId): int;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): OrganizationSupportAccess;

    public function lockForActor(string $organizationId, string $accessId, int $actorId): OrganizationSupportAccess;

    public function revoke(OrganizationSupportAccess $access): void;

    /** @param array<string, mixed> $attributes */
    public function audit(array $attributes): void;

    /** @return array<string, mixed> */
    public function diagnostic(string $organizationId, SupportAction $action): array;
}
