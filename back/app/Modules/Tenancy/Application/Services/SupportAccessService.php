<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Tenancy\Application\Data\SupportAccessData;
use App\Modules\Tenancy\Application\Data\SupportAuditData;
use App\Modules\Tenancy\Domain\Contracts\OrganizationRepository;
use App\Modules\Tenancy\Domain\Contracts\SupportAccessRepository;
use App\Modules\Tenancy\Domain\Models\OrganizationSupportAccess;
use App\Modules\Tenancy\Domain\SupportAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\Rule;

final readonly class SupportAccessService
{
    private const int MAX_ACTIVE_ACCESSES = 5;

    public function __construct(private SupportAccessRepository $accesses, private OrganizationRepository $organizations,
        private PlatformAccountDirectory $platform, private AccountDirectory $accounts, private DatabaseManager $database, private Factory $validation) {}

    public function authorizeOperator(int $actorId): void
    {
        if (! $this->platform->isTechnical($actorId) || ! $this->accounts->verifiedAccountExists($actorId) || ! $this->accounts->hasConfirmedMfa($actorId)) {
            throw new AuthorizationException;
        }
    }

    /** @return LengthAwarePaginator<int, OrganizationSupportAccess> */
    public function listing(int $actorId, int $perPage, int $page): LengthAwarePaginator
    {
        $this->authorizeOperator($actorId);
        $this->validation->make(['per_page' => $perPage, 'page' => $page], ['per_page' => ['integer', 'between:1,100'], 'page' => ['integer', 'between:1,100000']])->validate();

        return $this->accesses->paginate($actorId, $perPage, $page);
    }

    public function open(string $organizationId, SupportAccessData $data, SupportAuditData $audit): OrganizationSupportAccess
    {
        $this->validation->make(['organization_id' => $organizationId, 'reason' => trim($data->reason), 'duration_minutes' => $data->durationMinutes,
            'actions' => $data->actions, 'request_id' => $audit->requestId], [
                'organization_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'min:10', 'max:500'],
                'duration_minutes' => ['required', 'integer', 'between:1,'.OrganizationSupportAccess::MAX_DURATION_MINUTES], 'actions' => ['required', 'array', 'list', 'min:1', 'max:2'],
                'actions.*' => ['required', 'string', 'distinct', Rule::enum(SupportAction::class)], 'request_id' => ['required', 'uuid'],
            ])->validate();
        $this->authorizeOperator($audit->actorUserId);

        return $this->database->transaction(function () use ($organizationId, $data, $audit): OrganizationSupportAccess {
            $this->organizations->lock($organizationId);
            $this->authorizeOperator($audit->actorUserId);
            if ($this->accesses->activeCount($organizationId, $audit->actorUserId) >= self::MAX_ACTIVE_ACCESSES) {
                throw new DomainConflictException(__('platform.limit'), 'SUPPORT_ACCESS_LIMIT');
            }
            $start = now()->utc();
            $version = $this->platform->version($audit->actorUserId) ?? throw new AuthorizationException;
            $access = $this->accesses->create(['organization_id' => $organizationId, 'actor_user_id' => $audit->actorUserId,
                'reason' => trim($data->reason), 'actions' => $data->actions, 'status' => 'active',
                'platform_role_version' => $version,
                'starts_at' => $start, 'expires_at' => $start->copy()->addMinutes($data->durationMinutes)]);
            $this->record($access, 'opened', $audit);

            return $access;
        }, 3);
    }

    public function revoke(string $organizationId, string $accessId, SupportAuditData $audit): OrganizationSupportAccess
    {
        $this->authorizeOperator($audit->actorUserId);

        return $this->database->transaction(function () use ($organizationId, $accessId, $audit): OrganizationSupportAccess {
            $this->organizations->lock($organizationId);
            $access = $this->accesses->lockForActor($organizationId, $accessId, $audit->actorUserId);
            $this->authorizeOperator($audit->actorUserId);
            if ($access->status !== 'revoked') {
                $this->accesses->revoke($access);
                $this->record($access, 'revoked', $audit);
            }

            return $access;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function inspect(string $organizationId, string $accessId, SupportAction $action, SupportAuditData $audit): array
    {
        $this->authorizeOperator($audit->actorUserId);

        $result = $this->database->transaction(function () use ($organizationId, $accessId, $action, $audit): ?array {
            $this->organizations->lock($organizationId);
            $access = $this->accesses->lockForActor($organizationId, $accessId, $audit->actorUserId);
            $this->authorizeOperator($audit->actorUserId);
            if ($access->platform_role_version !== $this->platform->version($audit->actorUserId) || ! $access->allows($action->value, now())) {
                $this->record($access, $action->value.'.denied', $audit);

                return null;
            }
            $result = $this->accesses->diagnostic($organizationId, $action);
            $this->record($access, $action->value, $audit);

            return $result;
        }, 3);

        // Commit the refusal trace before raising; no diagnostic data was read.
        return $result ?? throw new AuthorizationException;
    }

    private function record(OrganizationSupportAccess $access, string $action, SupportAuditData $audit): void
    {
        $this->accesses->audit(['organization_id' => $access->organization_id, 'support_access_id' => $access->id,
            'actor_user_id' => $audit->actorUserId, 'action' => $action, 'request_id' => $audit->requestId,
            'ip_address' => $audit->ipAddress, 'occurred_at' => now()]);
    }
}
