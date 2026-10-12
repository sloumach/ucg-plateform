<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\People\Application\Services\PersonDepartureItemService;
use App\Modules\People\Application\Services\PersonLifecycleProjectionService;
use App\Modules\People\Domain\Models\PersonDepartureItem;
use App\Modules\People\Presentation\Http\Requests\Api\V1\PersonDepartureItemReadRequest;
use App\Modules\People\Presentation\Http\Requests\Api\V1\SavePersonDepartureItemRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\PersonLifecycleResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

final class PersonDepartureItemController extends Controller
{
    public function __construct(private readonly PersonDepartureItemService $service, private readonly PersonLifecycleProjectionService $projection, private readonly Gate $gate) {}

    public function index(PersonDepartureItemReadRequest $request, TenantContext $context, string $tenant, string $person): AnonymousResourceCollection
    {
        $this->gate->authorize('items', [PersonDepartureItem::class, $context]);
        $page = $this->service->list($context, $person, $request->integer('per_page', 20), $request->integer('page', 1));
        $records = new LengthAwarePaginator($this->projection->items($context, array_values($page->items())), $page->total(), $page->perPage(), $page->currentPage(), ['path' => $page->path()]);

        return PersonLifecycleResource::collection($records)->additional(['meta' => ['request_id' => $context->requestId, 'pending_items_count' => $this->service->pending($context, $person)]]);
    }

    public function store(SavePersonDepartureItemRequest $request, TenantContext $context, string $tenant, string $person): JsonResponse
    {
        $this->gate->authorize('manageItems', [PersonDepartureItem::class, $context]);
        $record = $this->service->save($context, $person, $request->toData(), null, $request->ip());

        return PersonLifecycleResource::make($this->projection->items($context, [$record])[0])
            ->additional(['notification' => ['type' => 'success', 'message' => __('people_lifecycle.saved')]])->response()->setStatusCode(201);
    }

    public function update(SavePersonDepartureItemRequest $request, TenantContext $context, string $tenant, string $person, string $record): PersonLifecycleResource
    {
        $this->gate->authorize('manageItems', [PersonDepartureItem::class, $context]);
        $saved = $this->service->save($context, $person, $request->toData(), $record, $request->ip());

        return PersonLifecycleResource::make($this->projection->items($context, [$saved])[0])->additional(['notification' => ['type' => 'success', 'message' => __('people_lifecycle.saved')]]);
    }
}
