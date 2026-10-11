<?php

namespace App\Modules\Identity\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Identity\Domain\Contracts\PlatformRoleRepository;
use App\Modules\Identity\Domain\Models\PlatformRole;
use App\Modules\Identity\Domain\PlatformRoleType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

final readonly class PlatformRoleService
{
    public function __construct(private PlatformRoleRepository $roles, private PlatformAccountDirectory $platform,
        private AccountDirectory $accounts, private DatabaseManager $database, private Factory $validation) {}

    public function configure(int $userId, PlatformRoleType $type, bool $active, ?int $actorId, string $reason, string $requestId, bool $bootstrap = false): PlatformRole
    {
        $reason = trim($reason);
        $this->validation->make(['user_id' => $userId, 'reason' => $reason, 'request_id' => $requestId], [
            'user_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:500'],
            'request_id' => ['required', 'uuid'],
        ])->validate();
        try {
            return $this->database->transaction(function () use ($userId, $type, $active, $actorId, $reason, $requestId, $bootstrap): PlatformRole {
                if ($bootstrap) {
                    if ($actorId !== null || ! $active || $type !== PlatformRoleType::Superadmin || $this->roles->hasBootstrap() || $this->roles->hasAnyRole()) {
                        throw new DomainConflictException(__('platform.bootstrap_closed'), 'PLATFORM_BOOTSTRAP_CLOSED');
                    }
                } elseif (! $this->roles->lockAdministration() || $actorId === null || ! $this->platform->isSuperadmin($actorId)
                    || ! $this->accounts->verifiedAccountExists($actorId) || ! $this->accounts->hasConfirmedMfa($actorId)) {
                    throw new AuthorizationException;
                }
                if (! $this->accounts->verifiedAccountExists($userId) || ($active && ! $this->accounts->hasConfirmedMfa($userId))) {
                    throw new DomainConflictException(__('platform.account_required'), 'PLATFORM_ACCOUNT_REQUIRED');
                }
                $before = $this->roles->findForAccount($userId);
                if ($before?->active && $before->role === PlatformRoleType::Superadmin
                    && (! $active || $type !== PlatformRoleType::Superadmin) && $this->roles->activeSuperadmins() <= 1) {
                    throw new DomainConflictException(__('platform.last_superadmin'), 'PLATFORM_LAST_SUPERADMIN');
                }
                $beforeType = $before?->role->value;
                $beforeActive = $before?->active;
                $beforeVersion = $before?->version;
                $role = $this->roles->save($userId, ['role' => $type, 'active' => $active, 'version' => (string) Str::uuid()]);
                $this->roles->audit(['user_id' => $userId, 'actor_user_id' => $actorId, 'before_role' => $beforeType,
                    'after_role' => $type->value, 'before_active' => $beforeActive, 'after_active' => $active,
                    'before_version' => $beforeVersion, 'after_version' => $role->version,
                    'reason' => trim($reason), 'request_id' => $requestId, 'occurred_at' => now(),
                    'bootstrap_slot' => $bootstrap ? 1 : null]);

                return $role;
            }, 3);
        } catch (QueryException $exception) {
            if ($bootstrap && in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw new DomainConflictException(__('platform.bootstrap_closed'), 'PLATFORM_BOOTSTRAP_CLOSED');
            }
            throw $exception;
        }
    }
}
