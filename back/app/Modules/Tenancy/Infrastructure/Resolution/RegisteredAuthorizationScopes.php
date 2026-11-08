<?php

namespace App\Modules\Tenancy\Infrastructure\Resolution;

use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeSource;
use App\Modules\Tenancy\Application\Contracts\DatedAuthorizationScopeDirectory;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;

final readonly class RegisteredAuthorizationScopes implements AuthorizationScopeSource, DatedAuthorizationScopeDirectory
{
    public const TYPES = ['organization', 'team', 'game', 'cohort', 'event'];

    public function choices(TenantContext $context, string $type, int $page, int $perPage, ?Builder $visible = null): LengthAwarePaginator
    {
        $this->sources($context->organizationId, $type);
        $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);
        if (! is_string($class) || ! is_a($class, AuthorizationScopeSource::class, true)) {
            throw new ModelNotFoundException;
        }

        return $this->container->make($class)->choices($context, $type, $page, $perPage, $visible);
    }

    public function sources(string $organizationId, string $type): Builder
    {
        $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);
        if (! is_string($class) || ! is_a($class, AuthorizationScopeSource::class, true) || $class === self::class) {
            throw new ModelNotFoundException;
        }

        return $this->container->make($class)->sources($organizationId, $type);
    }

    public function __construct(private Repository $config, private Container $container) {}

    public function exists(string $organizationId, string $type, string $id): bool
    {
        if ($type === 'organization') {
            return $organizationId === $id;
        }
        if (! in_array($type, self::TYPES, true)) {
            return false;
        }
        $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);
        if (! is_string($class) || ! is_a($class, AuthorizationScopeDirectory::class, true) || $class === self::class) {
            return false;
        }

        return $this->container->make($class)->exists($organizationId, $type, $id);
    }

    public function availableTypes(): array
    {
        return array_values(array_filter(self::TYPES, function (string $type): bool {
            if ($type === 'organization') {
                return true;
            }
            $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);

            return is_string($class) && is_a($class, AuthorizationScopeDirectory::class, true) && $class !== self::class;
        }));
    }

    public function periodStart(string $org, string $type, string $id, int $userId, CarbonImmutable $at, ?CarbonImmutable $end = null, bool $cover = false): ?CarbonImmutable
    {
        $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);
        if (! is_string($class) || ! is_a($class, DatedAuthorizationScopeDirectory::class, true) || $class === self::class) {
            return null;
        }

        return $this->container->make($class)->periodStart($org, $type, $id, $userId, $at, $end, $cover);
    }

    public function bindAssignment(string $org, string $type, string $id, int $userId, string $assignmentId, CarbonImmutable $start, ?CarbonImmutable $end): void
    {
        $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);
        if (! is_string($class) || ! is_a($class, DatedAuthorizationScopeDirectory::class, true) || $class === self::class) {
            throw new \LogicException('Dated scope unavailable.');
        }
        $this->container->make($class)->bindAssignment($org, $type, $id, $userId, $assignmentId, $start, $end);
    }

    public function assignmentIds(string $org, string $type, string $id, int $userId, CarbonImmutable $at): array
    {
        $class = $this->config->get('tenancy.authorization_scope_resolvers.'.$type);
        if (! is_string($class) || ! is_a($class, DatedAuthorizationScopeDirectory::class, true) || $class === self::class) {
            return [];
        }

        return $this->container->make($class)->assignmentIds($org, $type, $id, $userId, $at);
    }
}
