<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\ListOrganizationsRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\UpdateOrganizationRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\OrganizationResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationService $organizations, private readonly Gate $gate) {}

    public function index(ListOrganizationsRequest $request): AnonymousResourceCollection
    {
        return OrganizationResource::collection($this->organizations->listOwned(
            $this->userId($request), $request->integer('per_page', 20), $request->integer('page', 1),
        ))->additional(['meta' => ['request_id' => RequestId::for($request)]]);
    }

    public function show(Request $request, string $organization): OrganizationResource
    {
        $record = $this->organizations->findOwned($organization, $this->userId($request));
        $this->gate->authorize('view', $record);

        return OrganizationResource::make($record);
    }

    public function update(UpdateOrganizationRequest $request, string $organization): OrganizationResource
    {
        return OrganizationResource::make($this->organizations->updateOwned(
            $organization, $this->userId($request), $request->toData(),
        ))->additional(['notification' => ['type' => 'success', 'message' => __('tenancy.notifications.updated')]]);
    }

    private function userId(Request $request): int
    {
        $user = $request->user();
        abort_if($user === null, 401);

        return (int) $user->getAuthIdentifier();
    }
}
