<?php

namespace App\Modules\Academy\Presentation\Policies;

use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AcademyCoachingPolicy
{
    public function __construct(private TenantAuthorization $authorization) {}

    public function access(Authenticatable $user, TenantContext $context, string $kind, bool $write): bool
    {
        if ((int) $user->getAuthIdentifier() !== $context->actorUserId) {
            return false;
        }
        if (str_starts_with($kind, 'self')) {
            return ! $write && $this->authorization->allows($context, 'academy.self.read');
        }
        if (! $this->authorization->allows($context, 'academy.read')) {
            return false;
        }
        $permission = match ($kind) {
            'internal' => 'academy.notes.manage',
            'shared' => $write ? 'academy.feedback.manage' : null,
            default => $write ? 'academy.manage' : null,
        };

        return $permission === null || $this->authorization->allows($context, $permission);
    }
}
