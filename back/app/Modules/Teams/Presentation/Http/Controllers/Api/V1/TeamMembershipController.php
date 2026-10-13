<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\People\Application\Contracts\SportingPeopleDirectory;
use App\Modules\Teams\Application\Services\TeamMembershipService;
use App\Modules\Teams\Domain\Models\TeamMembership;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\TeamMembershipRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\TeamMembershipResource;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\TeamMovementResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TeamMembershipController extends Controller
{
    public function __construct(private readonly TeamMembershipService $memberships, private readonly SportingPeopleDirectory $people, private readonly Gate $gate) {}

    public function index(TeamMembershipRequest $request, TenantContext $context, string $tenant, string $team): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [TeamMembership::class, $context]);
        $page = $this->memberships->paginate($context, $team, $request->validated());
        $labels = $this->people->pseudonyms($context, array_values(array_unique(array_map(fn (TeamMembership $membership): string => $membership->person_id, $page->items()))));
        $at = $request->validated('at') === null ? CarbonImmutable::now() : CarbonImmutable::parse($request->validated('at'))->utc();
        foreach ($page->items() as $membership) {
            $membership->setAttribute('pseudonym', $labels[$membership->person_id] ?? null);
            $membership->setAttribute('evaluated_at', $at);
        }

        return TeamMembershipResource::collection($page)->additional(['meta' => ['request_id' => $context->requestId, 'evaluated_at' => $at->toIso8601String()]]);
    }

    public function movements(TeamMembershipRequest $request, TenantContext $context, string $tenant, string $team): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [TeamMembership::class, $context]);

        return TeamMovementResource::collection($this->memberships->movements($context, $team, $request->validated()))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function store(TeamMembershipRequest $request, TenantContext $context, string $tenant, string $team): JsonResponse
    {
        $this->gate->authorize('manage', [TeamMembership::class, $context]);

        return $this->result($this->memberships->create($context, $team, $request->validated()))->response()->setStatusCode(201);
    }

    public function update(TeamMembershipRequest $request, TenantContext $context, string $tenant, string $team, string $membership): TeamMembershipResource
    {
        $this->gate->authorize('manage', [TeamMembership::class, $context]);

        return $this->result($this->memberships->change($context, $team, $membership, $request->validated()));
    }

    private function result(TeamMembership $membership): TeamMembershipResource
    {
        return TeamMembershipResource::make($membership)->additional(['notification' => ['type' => 'success', 'message' => __('team_memberships.saved')]]);
    }
}
