<?php

namespace App\Modules\Tenancy\Presentation\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\ContextualScopeService;
use App\Modules\Tenancy\Presentation\Http\Requests\Api\V1\AuthorizationScopeRequest;
use App\Modules\Tenancy\Presentation\Http\Resources\Api\V1\AuthorizationScopeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

final class AuthorizationScopeController extends Controller
{
    public function choices(AuthorizationScopeRequest $request, TenantContext $context, ContextualScopeService $scopes): AnonymousResourceCollection
    {
        return AuthorizationScopeResource::collection($scopes->choices($context, (string) $request->route('scopeType'), $request->integer('page', 1), $request->integer('per_page', 10)));
    }

    public function capabilities(AuthorizationScopeRequest $request, TenantContext $context, ContextualScopeService $scopes): JsonResource
    {
        return JsonResource::make(['permissions' => $scopes->capabilities($context)]);
    }
}
