<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\ListOrganizationsRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\AccessibleOrganizationResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AccessibleOrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations) {}

    public function index(ListOrganizationsRequest $request): AnonymousResourceCollection
    {
        return AccessibleOrganizationResource::collection($this->organizations->listAccessible(
            (int) $request->user()?->getAuthIdentifier(), $request->integer('per_page', 20), $request->integer('page', 1),
        ));
    }
}
