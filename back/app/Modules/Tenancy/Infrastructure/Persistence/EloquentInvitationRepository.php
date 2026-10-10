<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\InvitationRepository;
use App\Modules\Tenancy\Domain\InvitationStatus;
use App\Modules\Tenancy\Domain\Models\OrganizationInvitation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentInvitationRepository implements InvitationRepository
{
    /** @return LengthAwarePaginator<int, OrganizationInvitation> */
    public function paginateForOrganization(string $organizationId, int $perPage, int $page): LengthAwarePaginator
    {
        return OrganizationInvitation::query()->where('organization_id', $organizationId)
            ->with('organization')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /** @return LengthAwarePaginator<int, OrganizationInvitation> */
    public function paginateForEmail(string $email, int $perPage, int $page): LengthAwarePaginator
    {
        return OrganizationInvitation::query()->where('email', $email)->with('organization')
            ->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $id, bool $lock = false): OrganizationInvitation
    {
        return OrganizationInvitation::query()->when($lock, fn (Builder $query) => $query->lockForUpdate())->findOrFail($id);
    }

    /** @return list<OrganizationInvitation> */
    public function pendingForEmail(string $organizationId, string $email): array
    {
        return array_values(OrganizationInvitation::query()->where('organization_id', $organizationId)
            ->where('email', $email)->where('status', InvitationStatus::Pending)->lockForUpdate()->get()->all());
    }

    /** @param array<string, mixed> $attributes */
    public function save(?OrganizationInvitation $invitation, array $attributes): OrganizationInvitation
    {
        $invitation ??= new OrganizationInvitation;
        $invitation->forceFill($attributes)->save();

        return $invitation->load('organization');
    }
}
