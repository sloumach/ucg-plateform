<?php

namespace App\Modules\Performance\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Performance\Application\Services\MetricCatalogueService;
use App\Modules\Performance\Domain\Models\MetricDefinition;
use App\Modules\Performance\Presentation\Http\Requests\Api\V1\MetricCatalogueRequest as Request;
use App\Modules\Performance\Presentation\Http\Resources\Api\V1\MetricCatalogueResource as Resource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class MetricCatalogueController extends Controller
{
    public function __construct(private readonly MetricCatalogueService $service, private readonly Gate $gate) {}

    public function index(Request $request): JsonResponse
    {
        $this->gate->authorize('viewAny', MetricDefinition::class);
        $meta = ['request_id' => RequestId::for($request), ...$this->service->metadata((int) $request->user()?->getAuthIdentifier())];
        $page = $this->service->index($request->validated());

        return Resource::collection(new LengthAwarePaginator(array_map($this->service->project(...), $page->items()), $page->total(), $page->perPage(), $page->currentPage(), ['path' => $request->url(), 'query' => $request->query()]))->additional(['meta' => $meta])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $metric): JsonResponse
    {
        $this->gate->authorize('viewAny', MetricDefinition::class);

        return Resource::make($this->service->project($this->service->find($metric)))->additional(['meta' => ['request_id' => RequestId::for($request), ...$this->service->metadata((int) $request->user()?->getAuthIdentifier())]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, string $metric): JsonResponse
    {
        $this->gate->authorize('viewAny', MetricDefinition::class);
        $definition = $this->service->find($metric);
        $page = $this->service->history($metric, $request->validated());

        return Resource::collection(new LengthAwarePaginator(array_map(fn ($r): array => $this->service->project($definition, $r), $page->items()), $page->total(), $page->perPage(), $page->currentPage(), ['path' => $request->url(), 'query' => $request->query()]))->additional(['meta' => ['request_id' => RequestId::for($request)]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function version(Request $request, string $metric, string $version): JsonResponse
    {
        $this->gate->authorize('viewAny', MetricDefinition::class);

        return Resource::make($this->service->project($this->service->find($metric), $this->service->version($metric, $version)))->additional(['meta' => ['request_id' => RequestId::for($request)]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request): JsonResponse
    {
        return $this->write($request, null);
    }

    public function update(Request $request, string $metric): JsonResponse
    {
        return $this->write($request, $metric);
    }

    private function write(Request $request, ?string $metric): JsonResponse
    {
        $this->gate->authorize('manage', MetricDefinition::class);
        $saved = $this->service->save((int) $request->user()?->getAuthIdentifier(), RequestId::for($request), $request->validated(), $metric);

        return Resource::make($this->service->project($saved))->additional(['meta' => ['request_id' => RequestId::for($request), ...$this->service->metadata((int) $request->user()?->getAuthIdentifier())], 'notification' => ['type' => 'success', 'message' => __('metric_catalogue.saved')]])->response()->setStatusCode($metric === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
