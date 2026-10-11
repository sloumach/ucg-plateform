<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\RoleManagementService;
use App\Modules\Tenancy\Application\Services\RoleProjectionService;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Permission;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\RoleListRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\SaveRoleRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\RoleCatalogueResource;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\RoleResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class RoleController extends Controller
{
    public function __construct(private readonly RoleManagementService $roles, private readonly Gate $gate,
        private readonly AuthorizationScopeDirectory $scopes, private readonly RoleProjectionService $projection) {}

    public function index(RoleListRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [OrganizationRole::class, $context]);

        $page = $this->roles->roles($context, $request->integer('per_page', 20), $request->integer('page', 1));
        $data = $this->projection->project($context, array_values($page->items()));

        return RoleResource::collection(new LengthAwarePaginator($data, $page->total(), $page->perPage(), $page->currentPage(),
            ['path' => $request->url(), 'query' => $request->query()]));
    }

    public function catalogue(RoleListRequest $request, TenantContext $context): RoleCatalogueResource
    {
        $this->gate->authorize('viewAny', [OrganizationRole::class, $context]);

        return RoleCatalogueResource::make(['permissions' => array_values(array_map(fn (Permission $permission): array => ['key' => $permission->value,
            'privileged' => $permission->privileged(), 'organization_only' => $permission->organizationOnly()],
            array_filter(Permission::cases(), fn (Permission $permission): bool => $permission->configurable()))),
            'scope_types' => $this->scopes->availableTypes(), 'can_manage' => $this->gate->allows('manage', [OrganizationRole::class, $context])]);
    }

    public function store(SaveRoleRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [OrganizationRole::class, $context]);

        return $this->result($context, $this->roles->saveRole($context, $request->toData()))->response()->setStatusCode(201);
    }

    public function update(SaveRoleRequest $request, TenantContext $context, string $tenant, string $role): RoleResource
    {
        $this->gate->authorize('manage', [OrganizationRole::class, $context]);

        return $this->result($context, $this->roles->saveRole($context, $request->toData(), $role));
    }

    private function result(TenantContext $context, OrganizationRole $role): RoleResource
    {
        return RoleResource::make($this->projection->project($context, [$role])[0])
            ->additional(['notification' => ['type' => 'success', 'message' => __('authorization.role_saved')]]);
    }
}
