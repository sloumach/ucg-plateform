<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\SupportAccessRepository;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportEvent;
use App\Modules\Tenancy\Domain\SupportAction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class EloquentSupportAccessRepository implements SupportAccessRepository
{
    public function paginate(int $actorId, int $perPage, int $page): LengthAwarePaginator
    {
        return OrganizationSupportAccess::query()->where('actor_user_id', $actorId)->orderByDesc('created_at')->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function activeCount(string $organizationId, int $actorId): int
    {
        return OrganizationSupportAccess::query()->where('organization_id', $organizationId)->where('actor_user_id', $actorId)
            ->where('status', 'active')->where('expires_at', '>', now())->count();
    }

    public function create(array $attributes): OrganizationSupportAccess
    {
        $access = new OrganizationSupportAccess;
        $access->forceFill($attributes)->save();

        return $access;
    }

    public function lockForActor(string $organizationId, string $accessId, int $actorId): OrganizationSupportAccess
    {
        return OrganizationSupportAccess::query()->where('organization_id', $organizationId)->where('actor_user_id', $actorId)
            ->whereKey($accessId)->lockForUpdate()->firstOrFail();
    }

    public function revoke(OrganizationSupportAccess $access): void
    {
        $access->forceFill(['status' => 'revoked'])->save();
    }

    public function audit(array $attributes): void
    {
        (new OrganizationSupportEvent)->forceFill($attributes)->save();
    }

    public function diagnostic(string $organizationId, SupportAction $action): array
    {
        if ($action === SupportAction::OrganizationInspect) {
            $org = Organization::query()->whereKey($organizationId)->firstOrFail(['id', 'status', 'timezone', 'language']);

            return ['organization_id' => $org->id, 'status' => $org->status->value, 'timezone' => $org->timezone, 'language' => $org->language];
        }

        return ['organization_id' => $organizationId,
            'memberships' => OrganizationMembership::query()->where('organization_id', $organizationId)->count(),
            'roles' => OrganizationRole::query()->where('organization_id', $organizationId)->count(),
            'role_assignments' => OrganizationRoleAssignment::query()->where('organization_id', $organizationId)->count()];
    }
}
