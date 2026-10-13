<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Services\TeamService;
use App\Modules\Teams\Domain\Models\Team;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\SaveTeamRequest;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\TeamReadRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\TeamResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teams, private readonly Gate $gate) {}

    public function index(TeamReadRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [Team::class, $context]);

        return TeamResource::collection($this->teams->paginate($context, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(TeamReadRequest $request, TenantContext $context, string $tenant, string $team): TeamResource
    {
        $this->gate->authorize('view', [Team::class, $context, $team]);

        return TeamResource::make($this->teams->find($context, $team));
    }

    public function store(SaveTeamRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [Team::class, $context]);

        return $this->result($this->teams->save($context, $request->validated(), ipAddress: $request->ip()))->response()->setStatusCode(201);
    }

    public function update(SaveTeamRequest $request, TenantContext $context, string $tenant, string $team): TeamResource
    {
        $this->gate->authorize('manage', [Team::class, $context]);

        return $this->result($this->teams->save($context, $request->validated(), $team, $request->ip()));
    }

    private function result(Team $team): TeamResource
    {
        return TeamResource::make($team)->additional(['notification' => ['type' => 'success', 'message' => __('teams.saved')]]);
    }
}
