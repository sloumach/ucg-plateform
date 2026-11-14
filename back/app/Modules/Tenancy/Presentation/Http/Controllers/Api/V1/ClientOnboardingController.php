<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Services\ClientOnboardingService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\ActivateClientRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\ClientInvitationRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\OrganizationResource;
use Illuminate\Http\JsonResponse;

final class ClientOnboardingController extends Controller
{
    public function __construct(private readonly ClientOnboardingService $clients, private readonly ApiResponseFactory $responses) {}

    public function store(ClientInvitationRequest $request): JsonResponse
    {
        $preview = $this->clients->invite((int) $request->user()?->getAuthIdentifier(), $request->validated());

        return $this->responses->success($request, ['activation_url' => $preview], status: 202,
            notification: ['type' => 'success', 'message' => __('platform.'.($preview === null ? 'client_invited' : 'client_preview'))])
            ->header('Cache-Control', 'no-store, private');
    }

    public function activate(ActivateClientRequest $request): OrganizationResource
    {
        return OrganizationResource::make($this->clients->activate($request->string('token')->toString(),
            $request->string('password')->toString(), RequestId::for($request)));
    }
}
