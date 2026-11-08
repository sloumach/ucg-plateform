<?php

namespace App\Modules\Academy\Infrastructure\Resolution;

use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeSource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class CohortAuthorizationScopes implements AuthorizationScopeSource
{
    public function __construct(private ConnectionInterface $database) {}

    public function eligibleAssignmentIds(string $org, string $type, int $userId): ?Builder
    {
        return null;
    }

    public function exists(string $organizationId, string $type, string $id): bool
    {
        return $type === 'cohort' && $this->sources($organizationId, $type)->where('id', $id)->exists();
    }

    public function availableTypes(): array
    {
        return ['cohort'];
    }

    public function choices(TenantContext $context, string $type, int $page, int $perPage, ?Builder $visible = null): LengthAwarePaginator
    {
        return $this->sources($context->organizationId, $type)->when($visible !== null, fn ($query) => $query->whereIn('id', $visible))->orderBy('label')->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
    }

    public function sources(string $organizationId, string $type): Builder
    {
        if ($type !== 'cohort') {
            throw new ModelNotFoundException;
        }

        return $this->database->table('academy_cohorts')->where('organization_id', $organizationId)->where('status', 'active')->select(['id', 'name as label']);
    }
}
