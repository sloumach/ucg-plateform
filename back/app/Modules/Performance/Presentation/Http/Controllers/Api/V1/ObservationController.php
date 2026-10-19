<?php

namespace App\Modules\Performance\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Performance\Application\Services\ObservationService;
use App\Modules\Performance\Domain\Models\StatisticalObservation as Observation;
use App\Modules\Performance\Presentation\Http\Requests\Api\V1\ObservationRequest as Request;
use App\Modules\Performance\Presentation\Http\Resources\Api\V1\ObservationResource as Resource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class ObservationController extends Controller
{
    public function __construct(private ObservationService $service, private Gate $gate) {}

    private function authorize(TenantContext $context, string $ability = 'viewAny'): void
    {
        $this->gate->authorize($ability, [Observation::class, $context]);
    }

    public function index(Request $request, TenantContext $context, string $tenant): JsonResponse
    {
        $this->authorize($context);
        $rows = $this->service->index($context, $request->validated());
        $labels = $this->service->labels($context, $rows->items());
        $projected = new LengthAwarePaginator(array_map(fn ($r): array => $this->service->project($r, $labels), $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage(), ['path' => $request->url(), 'query' => $request->query()]);

        return Resource::collection($projected)->additional(['meta' => ['request_id' => RequestId::for($request)]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, TenantContext $context, string $tenant, string $observation): JsonResponse
    {
        $this->authorize($context);

        return $this->response($request, $context, $this->service->find($context, $observation));
    }

    public function store(Request $request, TenantContext $context, string $tenant): JsonResponse
    {
        $this->authorize($context, 'create');

        return $this->response($request, $context, $this->service->save($context, $request->validated(), null, $request->ip()), 201, true);
    }

    public function update(Request $request, TenantContext $context, string $tenant, string $observation): JsonResponse
    {
        $this->authorize($context, 'correct');

        return $this->response($request, $context, $this->service->save($context, $request->validated(), $observation, $request->ip()), 200, true);
    }

    private function response(Request $request, TenantContext $context, Observation $row, int $status = 200, bool $saved = false): JsonResponse
    {
        return Resource::make($this->service->project($row, $this->service->labels($context, [$row])))->additional(['meta' => ['request_id' => RequestId::for($request)], ...($saved ? ['notification' => ['type' => 'success', 'message' => __('observations.saved')]] : [])])->response()->setStatusCode($status)->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, TenantContext $context, string $tenant, string $observation): JsonResponse
    {
        $this->authorize($context);
        $row = $this->service->find($context, $observation);
        $rows = $this->service->history($context, $observation, $request->validated());
        $labels = $this->service->labels($context, [$row]);
        $page = new LengthAwarePaginator(array_map(fn ($r): array => $this->service->project($row, $labels, $r), $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage(), ['path' => $request->url(), 'query' => $request->query()]);

        return Resource::collection($page)->additional(['meta' => ['request_id' => RequestId::for($request)]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function version(Request $request, TenantContext $context, string $tenant, string $observation, string $version): JsonResponse
    {
        $this->authorize($context);
        $row = $this->service->find($context, $observation);

        return Resource::make($this->service->project($row, $this->service->labels($context, [$row]), $this->service->version($context, $observation, $version)))->additional(['meta' => ['request_id' => RequestId::for($request)]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function choices(Request $request, TenantContext $context, string $tenant): JsonResponse
    {
        $this->authorize($context, 'create');

        $rows = $this->service->choices($context, $request->validated());
        $page = new LengthAwarePaginator($rows->items(), $rows->total(), $rows->perPage(), $rows->currentPage(), ['path' => $request->url(), 'query' => $request->query()]);

        return Resource::collection($page)->response()->header('Cache-Control', 'private, no-store');
    }
}
