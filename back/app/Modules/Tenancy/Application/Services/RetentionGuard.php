<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Application\Contracts\AccountDirectory;
use App\Modules\Identity\Application\Contracts\PlatformAccountDirectory;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;

final readonly class RetentionGuard
{
    public function __construct(private RetentionControlRepository $controls, private ConnectionInterface $database,
        private AccountDirectory $accounts, private PlatformAccountDirectory $platform, private Repository $config) {}

    public function authorizeOperator(string $organizationId, int $actorId): void
    {
        $targets = $this->config->get('retention.control_operators.'.$actorId);
        if (! is_array($targets) || ! in_array($organizationId, $targets, true)
            || ! $this->accounts->verifiedAccountExists($actorId) || ! $this->accounts->hasConfirmedMfa($actorId)
            || $this->platform->isTechnical($actorId)) {
            throw new AuthorizationException(__('retention.control_forbidden'));
        }
    }

    /** Serialize physical removal with hold acquisition; never retry external I/O automatically.
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function destructive(string $organizationId, Closure $operation): mixed
    {
        return $this->database->transaction(function () use ($organizationId, $operation): mixed {
            $this->lock($organizationId);
            $this->assertRemovalAllowed($organizationId);

            return $operation();
        });
    }

    /** Call while holding the organization lock. */
    public function assertRemovalAllowed(string $organizationId): void
    {
        $state = $this->controls->state($organizationId);
        if ($state['holds'] !== [] || $state['quarantine'] !== null) {
            throw new DomainConflictException(__('retention.preservation_active'), 'RETENTION_PRESERVATION_ACTIVE');
        }
    }

    public function assertReactivationAllowed(string $organizationId): void
    {
        $state = $this->controls->state($organizationId);
        if ($state['restricted_archive'] || $state['quarantine'] !== null) {
            throw new DomainConflictException(__('retention.reactivation_blocked'), 'RETENTION_REACTIVATION_BLOCKED');
        }
    }

    public function assertArchiveReadAllowed(string $organizationId, int $actorId): void
    {
        if ($this->controls->state($organizationId)['restricted_archive']) {
            $this->authorizeOperator($organizationId, $actorId);
        }
    }

    public function lock(string $organizationId): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('A retention lock requires a database transaction.');
        }
        if (! Str::isUuid($organizationId) || $this->database->table('organizations')->where('id', $organizationId)->lockForUpdate()->first(['id']) === null) {
            throw new ModelNotFoundException;
        }
    }
}
