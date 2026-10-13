<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Services\TeamLineupService;
use App\Modules\Teams\Domain\Models\TeamLineup;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\TeamLineupRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\TeamLineupResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TeamLineupController extends Controller
{
    public function __construct(private readonly TeamLineupService $lineups, private readonly Gate $gate) {}

    public function index(TeamLineupRequest $request, TenantContext $context, string $tenant, string $team): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [TeamLineup::class, $context]);

        return TeamLineupResource::collection($this->lineups->paginate($context, $team, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(TeamLineupRequest $request, TenantContext $context, string $tenant, string $team, string $lineup): TeamLineupResource
    {
        $this->gate->authorize('viewAny', [TeamLineup::class, $context]);

        return TeamLineupResource::make($this->lineups->show($context, $team, $lineup));
    }

    public function store(TeamLineupRequest $request, TenantContext $context, string $tenant, string $team): JsonResponse
    {
        $this->gate->authorize('manage', [TeamLineup::class, $context]);

        return $this->result($this->lineups->create($context, $team, $request->validated()))->response()->setStatusCode(201);
    }

    public function update(TeamLineupRequest $request, TenantContext $context, string $tenant, string $team, string $lineup): TeamLineupResource
    {
        $this->gate->authorize('manage', [TeamLineup::class, $context]);

        return $this->result($this->lineups->change($context, $team, $lineup, $request->validated()));
    }

    private function result(TeamLineup $lineup): TeamLineupResource
    {
        return TeamLineupResource::make($lineup)->additional(['notification' => ['type' => 'success', 'message' => __('team_lineups.saved')]]);
    }
}
