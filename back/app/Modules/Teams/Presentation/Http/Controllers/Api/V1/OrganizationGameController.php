<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Services\OrganizationGameService;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\OrganizationGameReadRequest;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\OrganizationGameRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\GameCatalogueResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class OrganizationGameController extends Controller
{
    public function __construct(private readonly OrganizationGameService $games, private readonly Gate $gate) {}

    public function index(OrganizationGameReadRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewTenant', [GameCatalogueEntry::class, $context]);
        $page = $this->games->entries($context, $request->validated());

        return GameCatalogueResource::collection(new LengthAwarePaginator($page['items'], $page['total'], $page['per_page'], $page['page'],
            ['path' => $request->url(), 'query' => $request->query()]))->additional(['meta' => ['request_id' => RequestId::for($request), 'catalogue_version' => $page['catalogue_version']]]);
    }

    public function show(OrganizationGameReadRequest $request, TenantContext $context, string $tenant, string $game): GameCatalogueResource
    {
        $this->gate->authorize('viewTenant', [GameCatalogueEntry::class, $context]);

        return GameCatalogueResource::make($this->games->find($context, $game));
    }

    public function update(OrganizationGameRequest $request, TenantContext $context, string $tenant, string $game): GameCatalogueResource
    {
        $this->gate->authorize('manageTenant', [GameCatalogueEntry::class, $context]);
        $this->games->save($context, $game, $request->validated(), $request->ip());

        return GameCatalogueResource::make($this->games->find($context, $game))->additional([
            'notification' => ['type' => 'success', 'message' => __('game_catalogue.organization_saved')]]);
    }
}
