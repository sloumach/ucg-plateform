<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Exceptions\DomainConflictException;
use App\Http\Api\RequestId;
use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Data\ActiveOrganizationData;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\ConfirmOrganizationRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\SelectOrganizationRequest;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\TenancyActionRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\ActiveOrganizationResource;
use App\Modules\Tenancy\Presentation\Support\ActiveOrganizationSession;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ActiveOrganizationController extends Controller
{
    public function __construct(private readonly TenantResolver $resolver, private readonly ActiveOrganizationSession $selection, private readonly Gate $gate) {}

    public function show(TenancyActionRequest $request): ActiveOrganizationResource
    {
        $current = $this->selection->current($request);
        if ($current === null) {
            return ActiveOrganizationResource::make(new ActiveOrganizationData(null));
        }
        try {
            $context = $this->resolver->resolve($current['organization_id'], $request->getHost(),
                (int) $request->user()?->getAuthIdentifier(), RequestId::for($request));
        } catch (ModelNotFoundException|DomainConflictException) {
            $request->session()->forget(ActiveOrganizationSession::KEY);

            return ActiveOrganizationResource::make(new ActiveOrganizationData(null))
                ->additional(['notification' => ['type' => 'warning', 'message' => __('tenancy.errors.context_changed')]]);
        }
        $this->gate->authorize('view', $context);

        return ActiveOrganizationResource::make(new ActiveOrganizationData($context, $current['revision'], $current['confirmed']));
    }

    public function store(SelectOrganizationRequest $request): ActiveOrganizationResource
    {
        $context = $this->resolver->resolve($request->string('organization_id')->toString(), $request->getHost(),
            (int) $request->user()?->getAuthIdentifier(), RequestId::for($request));
        $this->gate->authorize('view', $context);

        return ActiveOrganizationResource::make($this->selection->select($request, $context));
    }

    public function confirm(ConfirmOrganizationRequest $request): ActiveOrganizationResource
    {
        $context = $this->resolver->resolve($request->string('organization_id')->toString(), $request->getHost(),
            (int) $request->user()?->getAuthIdentifier(), RequestId::for($request));
        $this->gate->authorize('view', $context);

        return ActiveOrganizationResource::make($this->selection->confirm($request, $context, $request->string('revision')->toString()));
    }
}
