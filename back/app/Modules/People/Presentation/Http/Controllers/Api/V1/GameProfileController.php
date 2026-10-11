<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\People\Application\Services\GameProfileProjectionService;
use App\Modules\People\Application\Services\GameProfileService;
use App\Modules\People\Domain\Models\GameProfile;
use App\Modules\People\Presentation\Http\Requests\Api\V1\GameProfileReadRequest;
use App\Modules\People\Presentation\Http\Requests\Api\V1\SaveGameProfileRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\GameProfileResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class GameProfileController extends Controller
{
    public function __construct(private readonly GameProfileService $profiles, private readonly GameProfileProjectionService $projection,
        private readonly Gate $gate) {}

    public function index(GameProfileReadRequest $request, TenantContext $context, string $tenant, string $person): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [GameProfile::class, $context]);
        $page = $this->profiles->profiles($context, $person, $request->integer('per_page', 20), $request->integer('page', 1));
        $data = $this->projection->profiles($context, array_values($page->items()));

        return GameProfileResource::collection(new LengthAwarePaginator($data, $page->total(), $page->perPage(), $page->currentPage(),
            ['path' => $request->url(), 'query' => $request->query()]))->additional(['meta' => ['request_id' => RequestId::for($request)]]);
    }

    public function show(GameProfileReadRequest $request, TenantContext $context, string $tenant, string $person, string $profile): GameProfileResource
    {
        $this->gate->authorize('viewAny', [GameProfile::class, $context]);

        return GameProfileResource::make($this->projection->profiles($context, [$this->profiles->find($context, $person, $profile)])[0]);
    }

    public function store(SaveGameProfileRequest $request, TenantContext $context, string $tenant, string $person): JsonResponse
    {
        $this->gate->authorize('manage', [GameProfile::class, $context]);
        $profile = $this->profiles->save($context, $person, $request->toData(), ipAddress: $request->ip());

        return $this->result($context, $profile)->response()->setStatusCode(201);
    }

    public function update(SaveGameProfileRequest $request, TenantContext $context, string $tenant, string $person, string $profile): GameProfileResource
    {
        $this->gate->authorize('manage', [GameProfile::class, $context]);

        return $this->result($context, $this->profiles->save($context, $person, $request->toData(), $profile, $request->ip()));
    }

    private function result(TenantContext $context, GameProfile $profile): GameProfileResource
    {
        return GameProfileResource::make($this->projection->profiles($context, [$profile])[0])
            ->additional(['notification' => ['type' => 'success', 'message' => __('game_profiles.profile_saved')]]);
    }
}
