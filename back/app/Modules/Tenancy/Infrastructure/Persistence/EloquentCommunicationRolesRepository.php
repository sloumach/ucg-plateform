<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\CommunicationRolesRepository;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final readonly class EloquentCommunicationRolesRepository implements CommunicationRolesRepository
{
    public function current(string $org, int $userId, ?string $membershipId): array
    {
        $ids = OrganizationRoleAssignment::query()->where('organization_id', $org)->where('user_id', $userId)
            ->where('membership_id', $membershipId)->where('scope_type', 'organization')->where('scope_id', $org)
            ->where('status', 'active')->where('starts_at', '<=', now())
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->whereHas('role', fn (Builder $q) => $q->where('active', true))->distinct()->limit(1001)->get(['role_id'])->map(fn (OrganizationRoleAssignment $assignment): string => $assignment->role_id)->all();
        if (count($ids) > 1000) {
            throw ValidationException::withMessages(['audience' => __('communication.too_many_scopes')]);
        }

        return array_values($ids);
    }

    public function choices(string $org, int $page, int $perPage): LengthAwarePaginator
    {
        return OrganizationRole::query()->where('organization_id', $org)->where('active', true)->orderBy('name')->orderBy('id')
            ->paginate($perPage, ['id', 'name'], 'page', $page)
            ->through(fn (OrganizationRole $role): array => ['id' => $role->id, 'label' => $role->name]);
    }

    public function assertActive(string $org, string $id): void
    {
        OrganizationRole::query()->where('organization_id', $org)->where('active', true)->findOrFail($id);
    }
}
