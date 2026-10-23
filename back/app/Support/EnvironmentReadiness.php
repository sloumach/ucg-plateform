<?php

namespace App\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Encryption\Encrypter;

final readonly class EnvironmentReadiness
{
    public function __construct(private Repository $config) {}

    /** @return list<string> */
    public function failures(string $expected): array
    {
        $namespace = $this->string('operations.resource_namespace');
        $validNamespace = preg_match('/^ucg_(?:[a-z0-9]+_)*'.preg_quote($expected, '/').'$/D', $namespace) === 1;
        $domain = $this->string('operations.public_domain');
        $appHost = $this->httpsHost($this->string('app.url'));
        $frontHost = $this->httpsHost($this->string('cors.allowed_origins.0'));
        $database = 'database.connections.'.$this->string('database.default');
        $key = $this->string('app.key');
        $decodedKey = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $redis = $this->string('database.redis.options.prefix');
        $artifacts = 'filesystems.disks.'.$this->string('filesystems.artifacts');
        $root = str_replace('\\', '/', $this->string($artifacts.'.root'));

        // Les messages restent fixes : ne jamais exposer une valeur de configuration.
        $checks = [
            'environment: Le profil attendu et APP_ENV doivent correspondre.' => $this->config->get('app.env') === $expected,
            'namespace: Définir un namespace ucg_ propre au profil.' => $validNamespace,
            'debug: Désactiver APP_DEBUG.' => $this->config->get('app.debug') === false,
            'key: Injecter une APP_KEY compatible avec le chiffrement.' => is_string($decodedKey) && Encrypter::supported($decodedKey, $this->string('app.cipher')),
            'secrets: Déclarer une injection runtime ou mounted-file.' => in_array($this->config->get('operations.secrets_source'), ['runtime', 'mounted-file'], true),
            'data: Déclarer la classification des données du profil.' => $this->config->get('operations.data_classification') === ($expected === 'staging' ? 'synthetic' : 'production'),
            'https: Utiliser des origines HTTPS distinctes dans le domaine dédié.' => $domain !== '' && ! str_ends_with($domain, '.invalid') && $appHost !== null && $frontHost !== null && $appHost !== $frontHost && str_ends_with($appHost, '.'.$domain) && str_ends_with($frontHost, '.'.$domain),
            'cors: Limiter CORS à une origine explicite avec credentials.' => count((array) $this->config->get('cors.allowed_origins')) === 1 && $this->config->get('cors.allowed_origins_patterns') === [] && $this->config->get('cors.supports_credentials') === true,
            'database: Utiliser PostgreSQL, base et rôle dédiés, mot de passe injecté, sans DB_URL.' => $this->config->get($database.'.driver') === 'pgsql' && ! $this->config->get($database.'.url') && $validNamespace && $this->namespaced($this->string($database.'.database'), $namespace) && $this->namespaced($this->string($database.'.username'), $namespace) && $this->string($database.'.password') !== '',
            'redis: Définir un préfixe dédié, des DB distinctes et une authentification, sans REDIS_URL.' => $validNamespace && $this->namespaced($redis, $namespace) && ! $this->config->get('database.redis.default.url') && ! $this->config->get('database.redis.cache.url') && $this->string('database.redis.default.password') !== '' && $this->string('database.redis.cache.password') !== '' && $this->string('database.redis.default.database') !== $this->string('database.redis.cache.database'),
            'cache: Utiliser Redis et des préfixes dédiés pour cache et Horizon.' => $this->config->get('cache.default') === 'redis' && $this->config->get('tenancy.cache_store') === 'redis' && $validNamespace && $this->namespaced($this->string('cache.prefix'), $namespace) && $this->namespaced($this->string('horizon.prefix'), $namespace),
            'queues: Utiliser Redis et une configuration Horizon pour le profil.' => $this->config->get('queue.default') === 'redis' && $this->config->get('queue.connections.redis.connection') === 'default' && $this->config->get('queue.connections.redis.after_commit') === true && (int) $this->config->get('queue.connections.redis.retry_after') > 300 && is_array($this->config->get('horizon.environments.'.$expected)),
            'session: Activer Secure, HttpOnly, chiffrement, SameSite et cookie dédié.' => $this->config->get('session.secure') === true && $this->config->get('session.http_only') === true && $this->config->get('session.encrypt') === true && $this->config->get('session.same_site') === 'lax' && $this->config->get('session.domain') === '.'.$domain && $validNamespace && $this->namespaced($this->string('session.cookie'), $namespace),
            'sanctum: Déclarer seulement les hôtes API et frontend attendus.' => $appHost !== null && $frontHost !== null && $this->config->get('sanctum.stateful') === [$frontHost, $appHost],
            'reverb: Définir identité, clé publique, secret, TLS, origine et canal dédiés.' => $validNamespace
                && $this->namespaced($this->string('reverb.apps.apps.0.app_id'), $namespace)
                && $this->string('reverb.apps.apps.0.key') !== '' && $this->string('reverb.apps.apps.0.secret') !== ''
                && $this->config->get('reverb.apps.apps.0.options.scheme') === 'https'
                && $this->config->get('reverb.apps.apps.0.options.useTLS') === true
                && $this->config->get('reverb.apps.apps.0.options.host') === 'ws.'.$domain
                && $this->config->get('reverb.apps.apps.0.allowed_origins') === [$frontHost]
                && $this->namespaced($this->string('reverb.servers.reverb.scaling.channel'), $namespace),
            'artifacts: Utiliser le disque local privé dans un volume absolu dédié au namespace.' => $this->config->get($artifacts.'.driver') === 'local'
                && $this->string('filesystems.artifacts') === 'local' && $validNamespace
                && (str_starts_with($root, '/') || preg_match('/^[A-Za-z]:\//', $root) === 1)
                && str_contains('/'.trim($root, '/').'/', '/'.$namespace.'/') && str_ends_with($root, '/app/private')
                && array_intersect(explode('/', $root), ['.', '..', 'public']) === [],
            'mail: En recette, limiter les messages au transport log ou array.' => $expected !== 'staging' || in_array($this->config->get('mail.default'), ['log', 'array'], true),
        ];

        return array_keys(array_filter($checks, static fn (bool $passes): bool => ! $passes));
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_scalar($value) ? (string) $value : '';
    }

    private function namespaced(string $value, string $namespace): bool
    {
        return $value === $namespace || preg_match('/^'.preg_quote($namespace, '/').'[_:.-]/', $value) === 1;
    }

    private function httpsHost(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['query']) || isset($parts['fragment']) || ! in_array($parts['port'] ?? 443, [443], true) || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }

        return $parts['host'];
    }
}
