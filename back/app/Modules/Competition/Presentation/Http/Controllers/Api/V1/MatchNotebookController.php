<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\MatchNotebookService;
use App\Modules\Competition\Domain\Models\MatchRecord;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\MatchNotebookRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchNotebookResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class MatchNotebookController extends Controller
{
    public function __construct(private readonly MatchNotebookService $service, private readonly Gate $gate) {}

    public function index(MatchNotebookRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);
        $result = $this->service->index($context, $match, $request->validated());

        return MatchNotebookResource::collection($result['entries'])->additional(['writable_audiences' => $result['writable_audiences']])->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(MatchNotebookRequest $request, TenantContext $context, string $tenant, string $match, string $entry): JsonResponse
    {
        $this->gate->authorize('viewAny', [MatchRecord::class, $context]);

        return MatchNotebookResource::collection($this->service->history($context, $match, $entry, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(MatchNotebookRequest $request, TenantContext $context, string $tenant, string $match): JsonResponse
    {
        return $this->change($request, $context, $match, null);
    }

    public function update(MatchNotebookRequest $request, TenantContext $context, string $tenant, string $match, string $entry): JsonResponse
    {
        return $this->change($request, $context, $match, $entry);
    }

    private function change(MatchNotebookRequest $request, TenantContext $context, string $match, ?string $entry): JsonResponse
    {
        $this->gate->authorize('manage', [MatchRecord::class, $context]);

        return MatchNotebookResource::make($this->service->change($context, $match, $entry, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('match_notebook.saved')]])->response()->setStatusCode($entry === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }
}
