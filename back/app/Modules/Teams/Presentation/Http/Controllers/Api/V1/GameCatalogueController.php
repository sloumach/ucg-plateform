<?php

namespace App\Modules\Teams\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Teams\Application\Services\GameCatalogueService;
use App\Modules\Teams\Domain\Models\GameCatalogueEntry;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\GameCatalogueReadRequest;
use App\Modules\Teams\Presentation\Http\Requests\Api\V1\GameCatalogueRequest;
use App\Modules\Teams\Presentation\Http\Resources\Api\V1\GameCatalogueResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class GameCatalogueController extends Controller
{
    public function __construct(private readonly GameCatalogueService $catalogue, private readonly Gate $gate) {}

    public function index(GameCatalogueReadRequest $request): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', GameCatalogueEntry::class);
        $version = $this->catalogue->version();
        $page = $this->catalogue->entries($request->validated());
        $items = array_map($this->catalogue->project(...), $page->items());

        return GameCatalogueResource::collection(new LengthAwarePaginator($items, $page->total(), $page->perPage(), $page->currentPage(),
            ['path' => $request->url(), 'query' => $request->query()]))->additional(['meta' => [
                'request_id' => RequestId::for($request), 'catalogue_version' => $version,
                'can_manage' => $this->catalogue->canManage((int) $request->user()?->getAuthIdentifier())]]);
    }

    public function show(GameCatalogueReadRequest $request, string $entry): GameCatalogueResource
    {
        $this->gate->authorize('viewAny', GameCatalogueEntry::class);
        $version = $this->catalogue->version();

        return GameCatalogueResource::make($this->catalogue->project($this->catalogue->find($entry)))->additional(['meta' => ['catalogue_version' => $version]]);
    }

    public function store(GameCatalogueRequest $request): JsonResponse
    {
        $this->gate->authorize('manage', GameCatalogueEntry::class);

        return $this->result($this->catalogue->save((int) $request->user()?->getAuthIdentifier(), RequestId::for($request), $request->validated()))
            ->response()->setStatusCode(201);
    }

    public function update(GameCatalogueRequest $request, string $entry): GameCatalogueResource
    {
        $this->gate->authorize('manage', GameCatalogueEntry::class);

        return $this->result($this->catalogue->save((int) $request->user()?->getAuthIdentifier(), RequestId::for($request), $request->validated(), $entry));
    }

    private function result(GameCatalogueEntry $entry): GameCatalogueResource
    {
        return GameCatalogueResource::make($this->catalogue->project($entry))->additional([
            'meta' => ['catalogue_version' => $this->catalogue->version()],
            'notification' => ['type' => 'success', 'message' => __('game_catalogue.saved')]]);
    }
}
