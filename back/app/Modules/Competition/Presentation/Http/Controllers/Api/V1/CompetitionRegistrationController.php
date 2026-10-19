<?php

namespace App\Modules\Competition\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Competition\Application\Services\CompetitionRegistrationService;
use App\Modules\Competition\Domain\Models\CompetitionRegistrationRevision;
use App\Modules\Competition\Presentation\Http\Requests\Api\V1\CompetitionRegistrationRequest;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\CompetitionRegistrationReminderResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\CompetitionRegistrationResource;
use App\Modules\Competition\Presentation\Http\Resources\Api\V1\MatchReferenceResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;

final class CompetitionRegistrationController extends Controller
{
    public function __construct(private CompetitionRegistrationService $service, private Gate $gate) {}

    private function authorize(TenantContext $context, bool $write = false): void
    {
        $this->gate->authorize($write ? 'manage' : 'viewAny', [CompetitionRegistrationRevision::class, $context]);
    }

    public function show(CompetitionRegistrationRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context);

        $record = $this->service->read($context, $competition);

        return $record === null ? response()->json(['data' => null])->header('Cache-Control', 'private, no-store') : CompetitionRegistrationResource::make($record)->response()->header('Cache-Control', 'private, no-store');
    }

    public function save(CompetitionRegistrationRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context, true);

        return CompetitionRegistrationResource::make($this->service->save($context, $competition, $request->validated(), $request->ip()))->additional(['notification' => ['type' => 'success', 'message' => __('competition_registrations.saved')]])->response()->setStatusCode(200)->header('Cache-Control', 'private, no-store');
    }

    public function history(CompetitionRegistrationRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context);

        return CompetitionRegistrationResource::collection($this->service->history($context, $competition, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function people(CompetitionRegistrationRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context, true);

        return MatchReferenceResource::collection($this->service->people($context, $competition, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }

    public function reminders(CompetitionRegistrationRequest $request, TenantContext $context, string $tenant, string $competition): JsonResponse
    {
        $this->authorize($context);

        return CompetitionRegistrationReminderResource::collection($this->service->reminders($context, $competition, $request->validated()))->response()->header('Cache-Control', 'private, no-store');
    }
}
