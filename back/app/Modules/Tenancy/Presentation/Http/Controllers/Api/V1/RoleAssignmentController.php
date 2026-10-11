<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\RoleManagementService;
use App\Modules\Tenancy\Domain\Models\OrganizationRole;
use App\Modules\Tenancy\Domain\Models\OrganizationRoleAssignment;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\RoleListRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\SaveRoleAssignmentRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\RoleAssignmentResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class RoleAssignmentController extends Controller
{
    public function __construct(private readonly RoleManagementService $roles, private readonly Gate $gate) {}

    public function index(RoleListRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [OrganizationRole::class, $context]);

        return RoleAssignmentResource::collection($this->roles->assignments($context, $request->integer('per_page', 20), $request->integer('page', 1)));
    }

    public function mine(RoleListRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('view', $context);

        return RoleAssignmentResource::collection($this->roles->assignments($context, $request->integer('per_page', 20), $request->integer('page', 1), true));
    }

    public function store(SaveRoleAssignmentRequest $request, TenantContext $context): RoleAssignmentResource
    {
        $this->gate->authorize('assign', [OrganizationRole::class, $context]);

        return $this->result($this->roles->saveAssignment($context, $request->toData()));
    }

    public function update(SaveRoleAssignmentRequest $request, TenantContext $context, string $tenant, string $assignment): RoleAssignmentResource
    {
        $this->gate->authorize('assign', [OrganizationRole::class, $context]);

        return $this->result($this->roles->saveAssignment($context, $request->toData(), $assignment));
    }

    private function result(OrganizationRoleAssignment $assignment): RoleAssignmentResource
    {
        return RoleAssignmentResource::make($assignment)->additional(['notification' => ['type' => 'success', 'message' => __('authorization.assignment_saved')]]);
    }
}
