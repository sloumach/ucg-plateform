<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\People\Application\Services\PersonService;
use App\Modules\People\Domain\Models\Person;
use App\Modules\People\Presentation\Http\Requests\Api\V1\ListPeopleRequest;
use App\Modules\People\Presentation\Http\Requests\Api\V1\SavePersonRequest;
use App\Modules\People\Presentation\Http\Requests\Api\V1\ShowPersonRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\PersonResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PersonController extends Controller
{
    public function __construct(private readonly PersonService $people, private readonly Gate $gate) {}

    public function index(ListPeopleRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('view', [Person::class, $context]);

        return PersonResource::collection($this->people->paginate($context, $request->toQuery()))
            ->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function show(ShowPersonRequest $request, TenantContext $context, string $tenant, string $person): PersonResource
    {
        $this->gate->authorize('view', [Person::class, $context]);

        return PersonResource::make($this->people->find($context, $person));
    }

    public function store(SavePersonRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('create', [Person::class, $context]);

        return PersonResource::make($this->people->create($context, $request->toData(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('people.created')]])->response()->setStatusCode(201);
    }

    public function update(SavePersonRequest $request, TenantContext $context, string $tenant, string $person): PersonResource
    {
        $this->gate->authorize('update', [Person::class, $context]);

        return PersonResource::make($this->people->update($context, $person, $request->toData(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('people.updated')]]);
    }
}
