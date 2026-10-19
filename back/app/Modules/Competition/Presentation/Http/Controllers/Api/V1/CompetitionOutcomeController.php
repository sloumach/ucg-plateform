<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\CompetitionOutcomeService;
use App\Modules\Competition\Domain\Models\CompetitionOutcomeRevision as Revision;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\CompetitionOutcomeRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\CompetitionOutcomeMatchResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\CompetitionOutcomeResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\CompetitionOutcomeResultResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;

final class CompetitionOutcomeController extends Controller
{
    public function __construct(private CompetitionOutcomeService $service, private Gate $gate) {}

    private function authorize(TenantContext $context, string $stream, bool $write = false): void
    {
        $this->gate->authorize($write ? 'manage' : 'viewAny', [Revision::class, $context, $stream]);
    }

    public function show(CompetitionOutcomeRequest $request, TenantContext $context, string $tenant, string $competition, string $stream): JsonResponse
    {
        $this->authorize($context, $stream);
        $row = $this->service->read($context, $competition, $stream);

        return $row === null ? response()->json(['data' => null])->header('Cache-Control', 'private, no-store') : CompetitionOutcomeResource::make($this->service->project($row, $this->service->visible($context, [$row])))->response()->header('Cache-Control', 'private, no-store');
    }

    public function save(CompetitionOutcomeRequest $request, TenantContext $context, string $tenant, string $competition, string $stream): JsonResponse
    {
        $this->authorize($context, $stream, true);
        $row = $this->service->save($context, $competition, $stream, $request->validated(), $request->ip());

        return CompetitionOutcomeResource::make($this->service->project($row, $this->service->visible($context, [$row])))->additional(['notification' => ['type' => 'success', 'message' => __('competition_outcomes.saved')]])->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(CompetitionOutcomeRequest $request, TenantContext $context, string $tenant, string $competition, string $stream): JsonResponse
    {
        $this->authorize($context, $stream);
        $rows = $this->service->history($context, $competition, $stream, $request->validated());
        $visible = $this->service->visible($context, $rows->items());
        $projected = new LengthAwarePaginator(array_map(fn (Revision $row): array => $this->service->project($row, $visible), $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage(), ['path' => $request->url(), 'query' => $request->query()]);

        return CompetitionOutcomeResource::collection($projected)->response()->header('Cache-Control', 'private, no-store');
    }

    public function choices(CompetitionOutcomeRequest $request, TenantContext $context, string $tenant, string $competition, string $stream): JsonResponse
    {
        $this->authorize($context, 'sport', true);
        abort_unless($stream === 'sport', 404);
        $rows = $this->service->choices($context, $competition, $request->validated());
        $projected = new LengthAwarePaginator(array_map(fn ($match): array => CompetitionOutcomeService::matchSummary($match), $rows->items()), $rows->total(), $rows->perPage(), $rows->currentPage(), ['path' => $request->url(), 'query' => $request->query()]);

        return CompetitionOutcomeMatchResource::collection($projected)->response()->header('Cache-Control', 'private, no-store');
    }

    public function result(CompetitionOutcomeRequest $request, TenantContext $context, string $tenant, string $competition, string $stream, string $reference): JsonResponse
    {
        $this->authorize($context, 'sport');
        abort_unless($stream === 'sport', 404);

        return CompetitionOutcomeResultResource::make($this->service->result($context, $competition, $reference))->response()->header('Cache-Control', 'private, no-store');
    }
}
