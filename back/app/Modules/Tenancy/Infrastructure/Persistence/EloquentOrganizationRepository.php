<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationLifecycleEvent;
use App\Modules\Tenancy\Domain\Models\OrganizationSetting;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class EloquentOrganizationRepository implements OrganizationRepository
{
    /** @return LengthAwarePaginator<int, Organization> */
    public function paginateOwned(int $ownerId, int $perPage, int $page): LengthAwarePaginator
    {
        return Organization::query()->where('owner_user_id', $ownerId)->with('settings')
            ->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function findOwned(string $id, int $ownerId): Organization
    {
        return Organization::query()->where('owner_user_id', $ownerId)->with('settings')->findOrFail($id);
    }

    public function findBySlug(string $slug): ?Organization
    {
        return Organization::query()->where('slug', $slug)->with('settings')->first();
    }

    public function lock(string $id): Organization
    {
        return Organization::query()->lockForUpdate()->findOrFail($id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $settings
     */
    public function create(array $attributes, array $settings): Organization
    {
        $organization = new Organization;
        $organization->forceFill($attributes)->save();
        $parameters = new OrganizationSetting;
        $parameters->organization_id = $organization->id;
        $parameters->fill($settings)->save();

        return $organization->load('settings');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $settings
     */
    public function updateDetails(Organization $organization, array $attributes, array $settings): Organization
    {
        $organization->fill($attributes)->save();
        $parameters = OrganizationSetting::query()->findOrFail($organization->id);
        $parameters->fill($settings)->save();

        return $organization->load('settings');
    }

    public function setStatus(Organization $organization, OrganizationStatus $status): void
    {
        $organization->status = $status;
        $organization->save();
    }

    /** @param array<string, mixed> $attributes */
    public function recordLifecycleEvent(array $attributes): void
    {
        $event = new OrganizationLifecycleEvent;
        $event->forceFill($attributes)->save();
    }
}
