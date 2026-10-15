<?php

namespace App\Modules\Contracts\Presentation\Http\Controllers\Api\V1;

use App\Http\Api\ApiResponseFactory;
use App\Http\Controllers\Controller;
use App\Modules\Contracts\Application\Services\ContractReminderService;
use App\Modules\Contracts\Domain\Contracts\ContractReminderMailer;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Contracts\Presentation\Http\Requests\Api\V1\ContractReminderRequest;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractReminderDeliveryResource;
use App\Modules\Contracts\Presentation\Http\Resources\Api\V1\ContractReminderPlanResource;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ContractReminderController extends Controller
{
    public function __construct(private readonly ContractReminderService $service, private readonly Gate $gate, private readonly ApiResponseFactory $responses, private readonly Repository $config, private readonly ContractReminderMailer $mailer) {}

    public function show(ContractReminderRequest $request, TenantContext $context, string $tenant, string $contract): ContractReminderPlanResource|JsonResponse
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);
        $plan = $this->service->read($context, $contract);

        $meta = ['scheduler_enabled' => $this->config->get('contracts.reminders.enabled') === true, 'email_ready' => $this->mailer->available()];

        return $plan === null ? $this->responses->success($request, meta: $meta)->header('Cache-Control', 'private, no-store') : ContractReminderPlanResource::make($plan)->additional(['meta' => $meta]);
    }

    public function save(ContractReminderRequest $request, TenantContext $context, string $tenant, string $contract): ContractReminderPlanResource
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);
        $result = $this->service->save($context, $contract, $request->validated(), $request->ip());

        return ContractReminderPlanResource::make($result['plan'])->additional(['meta' => ['contract_version' => $result['contract_version']],
            'notification' => ['type' => 'success', 'message' => __('managed_contracts.reminder_saved')]]);
    }

    public function history(ContractReminderRequest $request, TenantContext $context, string $tenant, string $contract): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractReminderDeliveryResource::collection($this->service->history($context, $contract, $request->validated()));
    }

    public function inbox(ContractReminderRequest $request, TenantContext $context): AnonymousResourceCollection
    {
        $this->gate->authorize('viewAny', [ContractRecord::class, $context]);

        return ContractReminderDeliveryResource::collection($this->service->inbox($context, $request->validated()));
    }

    public function resolve(ContractReminderRequest $request, TenantContext $context, string $tenant, string $contract, string $delivery): ContractReminderDeliveryResource
    {
        $this->gate->authorize('manage', [ContractRecord::class, $context]);

        return ContractReminderDeliveryResource::make($this->service->resolve($context, $contract, $delivery, $request->validated(), $request->ip()))
            ->additional(['notification' => ['type' => 'success', 'message' => __('managed_contracts.reminder_resolved')]]);
    }
}
