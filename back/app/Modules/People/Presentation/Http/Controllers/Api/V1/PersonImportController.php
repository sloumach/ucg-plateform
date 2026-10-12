<?php

namespace App\Modules\People\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\People\Application\Services\PersonImportService;
use App\Modules\People\Domain\Models\PersonImport;
use App\Modules\People\Presentation\Http\Requests\Api\V1\PersonImportRequest;
use App\Modules\People\Presentation\Http\Resources\Api\V1\PersonImportResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

final class PersonImportController extends Controller
{
    public function __construct(private readonly PersonImportService $service, private readonly Gate $gate) {}

    public function index(PersonImportRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('manage', [PersonImport::class, $context]);

        return PersonImportResource::collection($this->service->list($context, (int) $request->validated('page', 1)));
    }

    public function show(PersonImportRequest $request, TenantContext $context, string $tenant, string $import): PersonImportResource
    {
        $this->gate->authorize('manage', [PersonImport::class, $context]);

        return PersonImportResource::make($this->service->show($context, $import, (int) $request->validated('page', 1)));
    }

    public function store(PersonImportRequest $request, TenantContext $context): PersonImportResource
    {
        $this->gate->authorize('manage', [PersonImport::class, $context]);
        $run = $this->service->upload($context, $request->file('file')->getContent(), $request->validated('delimiter'));

        return $this->result($context, $run->id, 'saved');
    }

    public function preview(PersonImportRequest $request, TenantContext $context, string $tenant, string $import): PersonImportResource
    {
        $this->gate->authorize('manage', [PersonImport::class, $context]);
        $run = $this->service->preview($context, $import, $request->validated());

        return $this->result($context, $run->id, 'previewed');
    }

    public function confirm(PersonImportRequest $request, TenantContext $context, string $tenant, string $import): PersonImportResource
    {
        $this->gate->authorize('manage', [PersonImport::class, $context]);
        $run = $this->service->confirm($context, $import, $request->validated());

        return $this->result($context, $run->id, 'queued');
    }

    public function report(PersonImportRequest $request, TenantContext $context, string $tenant, string $import): Response
    {
        $this->gate->authorize('manage', [PersonImport::class, $context]);

        return new Response($this->service->report($context, $import), 200,
            ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="people-import-report.csv"',
                'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function result(TenantContext $context, string $id, string $message): PersonImportResource
    {
        return PersonImportResource::make($this->service->show($context, $id))
            ->additional(['notification' => ['type' => 'success', 'message' => __('people_import.'.$message)]]);
    }
}
