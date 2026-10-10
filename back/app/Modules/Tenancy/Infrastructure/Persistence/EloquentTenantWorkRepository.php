<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\TenantWorkRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use InvalidArgumentException;

final readonly class EloquentTenantWorkRepository implements TenantWorkRepository
{
    public function find(string $organizationId): Organization
    {
        return Organization::query()->findOrFail(strtolower($organizationId));
    }

    public function activeIdsAfter(?string $after, int $limit): array
    {
        if ($limit < 1 || $limit > 201) {
            throw new InvalidArgumentException('Scheduled tenant pages must contain between one and 201 IDs.');
        }

        return array_values(Organization::query()->select('id')->where('status', OrganizationStatus::Active)
            ->when($after !== null, fn ($query) => $query->where('id', '>', $after))
            ->orderBy('id')->limit($limit)->get()->map(fn (Organization $organization): string => $organization->id)->all());
    }
}
