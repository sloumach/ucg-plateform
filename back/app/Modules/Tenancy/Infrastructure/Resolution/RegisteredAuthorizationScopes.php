<?php

namespace App\Modules\Tenancy\Infrastructure\Resolution;

use App\Modules\Tenancy\Application\Contracts\AuthorizationScopeDirectory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

final readonly class RegisteredAuthorizationScopes implements AuthorizationScopeDirectory
{
    public const TYPES = ['organization', 'team', 'game', 'cohort', 'event'];

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
}
