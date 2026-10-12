<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\People\Application\Services\PersonDepartureService;
use App\Modules\People\Domain\Models\PersonDeparture;
use App\Modules\People\Presentation\Http\Requests\Api\V1\PersonDepartureReadRequest;
use App\Modules\People\Presentation\Http\Requests\Api\V1\SavePersonDepartureRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\PersonLifecycleResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;

final class PersonDepartureController extends Controller
{
    public function __construct(private readonly PersonDepartureService $service, private readonly Gate $gate) {}

    public function show(PersonDepartureReadRequest $request, TenantContext $context, string $tenant, string $person): PersonLifecycleResource
    {
        $this->gate->authorize('view', [PersonDeparture::class, $context]);

        return PersonLifecycleResource::make($this->service->show($context, $person));
    }

    public function store(SavePersonDepartureRequest $request, TenantContext $context, string $tenant, string $person): PersonLifecycleResource
    {
        $this->gate->authorize('depart', [PersonDeparture::class, $context]);

        return PersonLifecycleResource::make($this->service->depart($context, $person, $request->toData(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('people_lifecycle.departure_saved')]]);
    }
}
