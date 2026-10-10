<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface OrganizationRepository
{
    /** @return LengthAwarePaginator<int, Organization> */
    public function paginateOwned(int $ownerId, int $perPage, int $page): LengthAwarePaginator;

    public function findOwned(string $id, int $ownerId): Organization;

    public function findBySlug(string $slug): ?Organization;

    public function lock(string $id): Organization;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $settings
     */
    public function create(array $attributes, array $settings): Organization;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $settings
     */
    public function updateDetails(Organization $organization, array $attributes, array $settings): Organization;

    public function setStatus(Organization $organization, OrganizationStatus $status): void;

    /** @param array<string, mixed> $attributes */
    public function recordLifecycleEvent(array $attributes): void;
}
