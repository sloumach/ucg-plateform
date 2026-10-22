<?php

namespace App\Modules\Academy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Academy\Application\Services\AcademyCoachingService;
use App\Modules\Academy\Domain\Models\AcademyObjective;
use App\Modules\Academy\Presentation\Http\Requests\Api\V1\AcademyCoachingRequest;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyCoachingRevisionResource;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyFeedbackResource;
use App\Modules\Academy\Presentation\Http\Resources\Api\V1\AcademyObjectiveResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AcademyCoachingController extends Controller
{
    public function __construct(private readonly AcademyCoachingService $service, private readonly Gate $gate) {}

    public function index(AcademyCoachingRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $kind = (string) $request->route('coaching_kind');
        $this->gate->authorize('access', [AcademyObjective::class, $context, $kind, false]);
        $page = $this->service->paginate($context, $kind, $request->validated(), $request->route('cohort'), $request->route('cycle'), $request->route('objective'));

        return match ($kind) {
            'objective', 'self' => AcademyObjectiveResource::collection($page),
            'history' => AcademyCoachingRevisionResource::collection($page),
            default => AcademyFeedbackResource::collection($page),
        };
    }

    public function save(AcademyCoachingRequest $request, TenantContext $context): JsonResponse
    {
        $kind = (string) $request->route('coaching_kind');
        $this->gate->authorize('access', [AcademyObjective::class, $context, $kind, true]);
        $record = $this->service->save($context, $kind, (string) $request->route('cohort'), (string) $request->route('cycle'),
            $request->route('objective'), $request->route('feedback'), $request->validated(), $request->ip());
        $resource = $record instanceof AcademyObjective ? AcademyObjectiveResource::make($record) : AcademyFeedbackResource::make($record);

        return $resource->additional(['notification' => ['type' => 'success', 'message' => __('coaching.saved')]])->response()->setStatusCode($request->isMethod('POST') ? 201 : 200);
    }
}
