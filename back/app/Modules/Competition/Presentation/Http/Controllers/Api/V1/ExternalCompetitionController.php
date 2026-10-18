<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\ExternalCompetitionDocumentService;
use App\Modules\Competition\Application\Services\ExternalCompetitionService;
use App\Modules\Competition\Domain\Models\ExternalCompetition;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\ExternalCompetitionRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\ExternalCompetitionDocumentResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\ExternalCompetitionResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\ExternalCompetitionRevisionResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchReferenceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ExternalCompetitionController extends Controller
{
    public function __construct(private readonly ExternalCompetitionService $service, private readonly ExternalCompetitionDocumentService $documents, private readonly Gate $gate) {}

    public function index(ExternalCompetitionRequest $request, TenantContext $context): JsonResponse
    {
        $this->authorize($context);

        return ExternalCompetitionResource::collection($this->service->paginate($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function show(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context);

        return ExternalCompetitionResource::make($this->service->find($context, $competition))->response()->header('Cache-Control', 'private, no-store');
    }

    public function history(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context);

        return ExternalCompetitionRevisionResource::collection($this->service->history($context, $competition, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function games(ExternalCompetitionRequest $request, TenantContext $context): JsonResponse
    {
        $this->authorize($context, true);

        return MatchReferenceResource::collection($this->service->games($context, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function store(ExternalCompetitionRequest $request, TenantContext $context): JsonResponse
    {
        return $this->save($request, $context, null);
    }

    public function update(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        return $this->save($request, $context, $competition);
    }

    private function save(ExternalCompetitionRequest $request, TenantContext $context, ?string $competition): JsonResponse
    {
        $this->authorize($context, true);

        return ExternalCompetitionResource::make($this->service->save($context, $request->validated(), $competition, $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('external_competitions.saved')]])->response()->setStatusCode($competition === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function documents(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context);

        return ExternalCompetitionDocumentResource::collection($this->documents->paginate($context, $competition, null, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function documentHistory(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition, string $document): JsonResponse
    {
        $this->authorize($context);

        return ExternalCompetitionDocumentResource::collection($this->documents->paginate($context, $competition, $document, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function documentStore(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        return $this->saveDocument($request, $context, $competition, null);
    }

    public function documentUpdate(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition, string $document): JsonResponse
    {
        return $this->saveDocument($request, $context, $competition, $document);
    }

    private function saveDocument(ExternalCompetitionRequest $request, TenantContext $context, string $competition, ?string $document): JsonResponse
    {
        $this->authorize($context, true);

        return ExternalCompetitionDocumentResource::make($this->documents->save($context, $competition, $document, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('external_competitions.document_saved')]])->response()->setStatusCode($document === null ? 201 : 200)->header('Cache-Control', 'private, no-store');
    }

    public function download(ExternalCompetitionRequest $request, TenantContext $context, string $tenant, string $competition, string $document, string $version): Response
    {
        $this->authorize($context);
        $revision = $this->documents->download($context, $competition, $document, $version);

        return new Response($revision->title."\n\n".$revision->body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="competition-document-'.$revision->id.'.txt"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    private function authorize(TenantContext $context, bool $write = false): void
    {
        $this->gate->authorize($write ? 'manage' : 'viewAny', [ExternalCompetition::class, $context]);
    }
}
