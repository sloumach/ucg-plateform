<?php

namespace App\Modules\Academy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Academy\Application\Services\AcademyService;
use App\Modules\Academy\Domain\Models\Cohort;
use App\Modules\Academy\Presentation\Http\Requests\Api\V1\AcademyRequest;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyResource;
use App\Modules\People\Application\Contracts\AcademyPeopleDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

final class AcademyController extends Controller
{
    public function __construct(private readonly AcademyService $academy, private readonly Gate $gate) {}

    public function index(AcademyRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [Cohort::class, $context]);

        return AcademyResource::collection($this->academy->paginate($context, (string) $request->route('academy_kind'),
            $request->validated(), $request->route('cohort')))->additional(['meta' => ['request_id' => $context->requestId]]);
    }

    public function choices(AcademyRequest $request, TenantContext $context, AcademyPeopleDirectory $people): AnonymousResourceCollection
    {
        $this->gate->authorize('manage', [Cohort::class, $context]);
        $values = $request->validated();

        return JsonResource::collection($people->choices($context, (int) ($values['page'] ?? 1), (int) ($values['per_page'] ?? 10)));
    }

    public function store(AcademyRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [Cohort::class, $context]);

        return AcademyResource::make($this->academy->save($context, (string) $request->route('academy_kind'),
            $request->validated(), $request->route('cohort'), ip: $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('academy.saved')]])->response()->setStatusCode(201);
    }

    public function update(AcademyRequest $request, TenantContext $context): AcademyResource
    {
        $this->gate->authorize('manage', [Cohort::class, $context]);
        $kind = (string) $request->route('academy_kind');

        return AcademyResource::make($this->academy->save($context, $kind, $request->validated(),
            $kind === 'cohort' ? null : $request->route('cohort'), $request->route('resource'), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('academy.saved')]]);
    }
}
