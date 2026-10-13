<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Services\LineupEligibilityService;
use App\Modules\Teams\Application\Services\TeamEligibilityRulesService;
use App\Modules\Teams\Domain\Models\TeamEligibilityRuleset;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\TeamEligibilityRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\TeamEligibilityResource;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\TeamLineupResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TeamEligibilityController extends Controller
{
    public function __construct(private readonly TeamEligibilityRulesService $rules, private readonly LineupEligibilityService $eligibility, private readonly Gate $gate) {}

    public function index(TeamEligibilityRequest $request, TenantContext $context, string $tenant): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [TeamEligibilityRuleset::class, $context]);

        return TeamEligibilityResource::collection($this->rules->list($context, $request->validated()));
    }

    public function store(TeamEligibilityRequest $request, TenantContext $context, string $tenant): JsonResponse
    {
        $this->gate->authorize('manage', [TeamEligibilityRuleset::class, $context]);

        return TeamEligibilityResource::make($this->rules->create($context, $request->validated()))->additional($this->notification())->response()->setStatusCode(201);
    }

    public function archive(TeamEligibilityRequest $request, TenantContext $context, string $tenant, string $ruleset): TeamEligibilityResource
    {
        $this->gate->authorize('manage', [TeamEligibilityRuleset::class, $context]);

        return TeamEligibilityResource::make($this->rules->archive($context, $ruleset, $request->validated()))->additional($this->notification());
    }

    public function bind(TeamEligibilityRequest $request, TenantContext $context, string $tenant, string $team, string $lineup): TeamLineupResource
    {
        $this->gate->authorize('manage', [TeamEligibilityRuleset::class, $context]);

        return TeamLineupResource::make($this->eligibility->bind($context, $team, $lineup, $request->validated()))->additional($this->notification());
    }

    public function evaluate(TeamEligibilityRequest $request, TenantContext $context, string $tenant, string $team, string $lineup): JsonResponse
    {
        $this->gate->authorize('manage', [TeamEligibilityRuleset::class, $context]);

        return TeamEligibilityResource::make($this->eligibility->preview($context, $team, $lineup, $request->validated()))->additional($this->notification())->response()->setStatusCode(201);
    }

    public function reports(TeamEligibilityRequest $request, TenantContext $context, string $tenant, string $team, string $lineup): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [TeamEligibilityRuleset::class, $context]);

        return TeamEligibilityResource::collection($this->eligibility->reports($context, $team, $lineup, $request->validated()));
    }

    /** @return array<string,array<string,string>> */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('team_eligibility.saved')]];
    }
}
