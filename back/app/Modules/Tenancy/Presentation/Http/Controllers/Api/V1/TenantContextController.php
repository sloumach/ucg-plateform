<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\TenantContextRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\TenantContextResource;

final class TenantContextController extends Controller
{
    public function show(TenantContextRequest $request, TenantContext $context): TenantContextResource
    {
        return TenantContextResource::make($context);
    }
}
