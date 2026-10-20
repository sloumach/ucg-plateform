<?php

namespace App\Modules\Reporting\Presentation\Policies;

use App\Modules\Reporting\Application\Services\TenantSearchService;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class TenantSearchPolicy
{
    public function __construct(private TenantSearchService $search) {}

    public function viewAny(Authenticatable $user, TenantContext $context): bool
    {
        return (int) $user->getAuthIdentifier() === $context->actorUserId && $this->search->types($context) !== [];
    }
}
