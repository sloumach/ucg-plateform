<?php

namespace App\Modules\Media\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Media\Application\Services\WorkTaskService;
use App\Modules\Media\Domain\Models\WorkTask;
use App\Modules\Media\Presentation\Http\Requests\Api\V1\WorkTaskRequest;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\MediaTeamResource;
use App\Modules\Media\Presentation\Http\Resources\Api\V1\WorkTaskResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class WorkTaskController extends Controller
{
    public function __construct(private readonly WorkTaskService $service, private readonly Gate $gate) {}

    public function index(WorkTaskRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('viewAny', [WorkTask::class, $context]);

        return WorkTaskResource::collection($this->service->paginate($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(WorkTaskRequest $request, TenantContext $context, string $tenant, string $task): JsonResponse
    {
        $this->gate->authorize('viewAny', [WorkTask::class, $context]);

        return WorkTaskResource::make($this->service->find($context, $task))->response()->header('Cache-Control', 'private, no-store');
    }

    public function people(WorkTaskRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [WorkTask::class, $context]);

        return MediaTeamResource::collection($this->service->people($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(WorkTaskRequest $request, TenantContext $context): JsonResponse
    {
        $this->gate->authorize('manage', [WorkTask::class, $context]);

        return WorkTaskResource::make($this->service->save($context, $request->validated(), ip: $request->ip()))
            ->additional($this->notification())->response()->setStatusCode(201)->header('Cache-Control', 'private, no-store');
    }

    public function update(WorkTaskRequest $request, TenantContext $context, string $tenant, string $task): JsonResponse
    {
        $this->gate->authorize('manage', [WorkTask::class, $context]);

        return WorkTaskResource::make($this->service->save($context, $request->validated(), $task, $request->ip()))
            ->additional($this->notification())->response()->header('Cache-Control', 'private, no-store');
    }

    /** @return array{notification: array{type: string, message: string}} */
    private function notification(): array
    {
        return ['notification' => ['type' => 'success', 'message' => __('communication.task_saved')]];
    }
}
