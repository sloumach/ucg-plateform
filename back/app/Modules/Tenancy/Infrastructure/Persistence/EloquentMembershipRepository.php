<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\MembershipRepository;
use App\Modules\Tenancy\Domain\MembershipStatus;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class EloquentMembershipRepository implements MembershipRepository
{
    /** @return LengthAwarePaginator<int, OrganizationMembership> */
    public function paginate(string $organizationId, int $perPage, int $page): LengthAwarePaginator
    {
        return OrganizationMembership::query()->where('organization_id', $organizationId)
            ->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $organizationId, string $id): OrganizationMembership
    {
        return OrganizationMembership::query()->where('organization_id', $organizationId)->findOrFail($id);
    }

    public function findForUser(string $id, int $userId): OrganizationMembership
    {
        return OrganizationMembership::query()->where('user_id', $userId)->findOrFail($id);
    }

    public function forAccount(string $organizationId, int $userId, bool $lock = false): ?OrganizationMembership
    {
        return OrganizationMembership::query()->where('organization_id', $organizationId)->where('user_id', $userId)
            ->when($lock, fn (Builder $query) => $query->lockForUpdate())->first();
    }

    public function overlapsAnotherOrganization(?string $organizationId, int $userId, CarbonInterface $start, ?CarbonInterface $end): bool
    {
        return OrganizationMembership::query()->where('user_id', $userId)
            ->when($organizationId !== null, fn (Builder $query) => $query->where('organization_id', '!=', $organizationId))
            ->whereIn('status', [MembershipStatus::Active, MembershipStatus::Suspended])
            ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', $start))
            ->when($end !== null, fn (Builder $query) => $query->where('starts_at', '<', $end))->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function save(?OrganizationMembership $membership, array $attributes): OrganizationMembership
    {
        $membership ??= new OrganizationMembership;
        $membership->forceFill($attributes)->save();

        return $membership;
    }
}
