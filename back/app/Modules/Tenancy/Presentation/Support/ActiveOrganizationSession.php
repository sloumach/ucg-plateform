<?php

namespace App\Modules\Tenancy\Presentation\Support;

use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\ActiveOrganizationData;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ActiveOrganizationSession
{
    public const string KEY = 'tenancy.active_organization';

    public const string REVISION_HEADER = 'X-Tenant-Revision';

    /** @return array{organization_id: string, actor_user_id: int, revision: string, confirmed: bool}|null */
    public function current(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $value = $request->session()->get(self::KEY);
        if (! is_array($value) || ! is_string($value['organization_id'] ?? null)
            || ! is_string($value['revision'] ?? null) || ! is_int($value['actor_user_id'] ?? null)
            || ! is_bool($value['confirmed'] ?? null)
            || ! Str::isUuid($value['organization_id']) || ! Str::isUuid($value['revision'])
            || $value['actor_user_id'] !== (int) $request->user()?->getAuthIdentifier()) {
            $request->session()->forget(self::KEY);

            return null;
        }

        return ['organization_id' => $value['organization_id'], 'actor_user_id' => $value['actor_user_id'],
            'revision' => $value['revision'], 'confirmed' => $value['confirmed']];
    }

    public function select(Request $request, TenantContext $context): ActiveOrganizationData
    {
        if (! $request->hasSession()) {
            throw new TenantContextRequiredException;
        }
        $revision = (string) Str::uuid();
        $request->session()->put(self::KEY, ['organization_id' => $context->organizationId, 'actor_user_id' => $context->actorUserId,
            'revision' => $revision, 'confirmed' => false]);

        return new ActiveOrganizationData($context, $revision);
    }

    public function confirm(Request $request, TenantContext $context, string $revision): ActiveOrganizationData
    {
        $current = $this->current($request);
        if ($current === null || $current['organization_id'] !== $context->organizationId || $current['revision'] !== $revision) {
            $this->changed();
        }
        $request->session()->put(self::KEY, [...$current, 'confirmed' => true]);

        return new ActiveOrganizationData($context, $revision, true);
    }

    public function assertCurrent(Request $request, TenantContext $context): void
    {
        $current = $this->current($request);
        if ($current !== null && ($current['organization_id'] !== $context->organizationId
            || $request->header(self::REVISION_HEADER) !== $current['revision'])) {
            $this->changed();
        }
    }

    /** Global invitation/departure actions target their own immutable resource, not a tenant route. */
    public function assertRevision(Request $request): void
    {
        $current = $this->current($request);
        if ($current !== null && $request->header(self::REVISION_HEADER) !== $current['revision']) {
            $this->changed();
        }
    }

    public function assertConfirmed(Request $request, TenantContext $context): void
    {
        $this->assertCurrent($request, $context);
        $current = $this->current($request);
        // Explicit route-only API clients have no prior session switch to confirm.
        if ($current !== null && ! $current['confirmed']) {
            throw new DomainConflictException(__('tenancy.errors.confirm_context'), 'TENANT_CONFIRMATION_REQUIRED');
        }
    }

    private function changed(): never
    {
        throw new DomainConflictException(__('tenancy.errors.context_changed'), 'TENANT_CONTEXT_CHANGED');
    }
}
