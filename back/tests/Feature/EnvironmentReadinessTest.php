<?php

namespace Tests\Feature;

use App\Support\EnvironmentReadiness;
use Dotenv\Dotenv;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EnvironmentReadinessTest extends TestCase
{
    public function test_valid_configuration_is_checked_without_connecting_to_resources(): void
    {
        $this->configureProfile();
        $this->assertSame([], app(EnvironmentReadiness::class)->failures('staging'));
        $this->consoleCommand('ops:check-environment --expect=staging --json')
            ->expectsOutput('{"configuration_valid":true,"failures":[],"scope":"configuration_only"}')
            ->assertSuccessful();
    }

    public function test_valid_production_configuration_passes(): void
    {
        $this->configureProfile();
        $replace = function (mixed $value) use (&$replace): mixed {
            if (is_array($value)) {
                return array_map($replace, $value);
            }

            return is_string($value) ? str_replace(['staging', 'synthetic'], ['production', 'production'], $value) : $value;
        };
        config($replace(config()->all()));
        $this->consoleCommand('ops:check-environment --expect=production')->assertSuccessful();
    }

    public function test_production_profile_requires_matching_resource_names(): void
    {
        $this->configureProfile();
        $failures = app(EnvironmentReadiness::class)->failures('production');
        $this->assertStringContainsString('environment:', implode(' ', $failures));
        $this->assertStringContainsString('namespace:', implode(' ', $failures));
        $this->assertStringContainsString('data:', implode(' ', $failures));
    }

    #[DataProvider('unsafeSettings')]
    public function test_unsafe_configuration_fails_without_exposing_values(string $setting, mixed $value, string $code): void
    {
        $this->configureProfile();
        config([$setting => $value]);
        $failures = app(EnvironmentReadiness::class)->failures('staging');
        $this->assertStringContainsString($code.':', implode(' ', $failures));
        $this->assertStringNotContainsString('sensitive-sentinel', implode(' ', $failures));
        $this->consoleCommand('ops:check-environment --expect=staging --json')->assertFailed();
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function unsafeSettings(): array
    {
        return [
            'debug' => ['app.debug', true, 'debug'],
            'bad key' => ['app.key', 'sensitive-sentinel', 'key'],
            'bad encoding' => ['app.key', 'base64:!!!', 'key'],
            'unproven secrets' => ['operations.secrets_source', null, 'secrets'],
            'real staging data' => ['operations.data_classification', 'production', 'data'],
            'insecure URL' => ['app.url', 'http://api.ucg-staging.example.org', 'https'],
            'URL credentials' => ['app.url', 'https://user:sensitive-sentinel@api.ucg-staging.example.org', 'https'],
            'wildcard CORS' => ['cors.allowed_origins', ['*'], 'https'],
            'foreign DB' => ['database.connections.pgsql.database', 'ucg_production', 'database'],
            'DB override' => ['database.connections.pgsql.url', 'postgres://sensitive-sentinel', 'database'],
            'foreign Redis' => ['database.redis.options.prefix', 'ucg_production_', 'redis'],
            'Redis override' => ['database.redis.default.url', 'redis://sensitive-sentinel', 'redis'],
            'shared Redis DB' => ['database.redis.cache.database', 0, 'redis'],
            'synchronous queues' => ['queue.default', 'sync', 'queues'],
            'short retry delay' => ['queue.connections.redis.retry_after', 300, 'queues'],
            'foreign cookie' => ['session.cookie', 'ucg_production_session', 'session'],
            'insecure cookie' => ['session.secure', false, 'session'],
            'foreign SPA' => ['sanctum.stateful', ['app.production.example.org'], 'sanctum'],
            'open realtime' => ['reverb.apps.apps.0.allowed_origins', ['*'], 'reverb'],
            'public disk' => ['filesystems.artifacts', 'public', 'artifacts'],
            'shared volume' => ['filesystems.disks.local.root', '/srv/shared/app/private', 'artifacts'],
            'path traversal' => ['filesystems.disks.local.root', '/srv/ucg_staging/../shared/app/private', 'artifacts'],
            'webroot volume' => ['filesystems.disks.local.root', '/srv/ucg_staging/public/app/private', 'artifacts'],
            'foreign realtime host' => ['reverb.apps.apps.0.options.host', 'ws.ucg-production.example.org', 'reverb'],
            'real mail' => ['mail.default', 'smtp', 'mail'],
        ];
    }

    public function test_expected_profile_is_required_and_never_reflected(): void
    {
        $this->consoleCommand('ops:check-environment')->assertExitCode(2);
        $this->consoleCommand('ops:check-environment --expect=sensitive-sentinel')->assertExitCode(2);
    }

    public function test_remote_examples_have_no_secrets_and_keep_processing_disabled(): void
    {
        foreach (['staging', 'production'] as $profile) {
            $contents = file_get_contents(base_path('.env.'.$profile.'.example'));
            $this->assertIsString($contents);
            $variables = Dotenv::parse($contents);
            $this->assertSame($profile, $variables['APP_ENV']);
            $this->assertSame('false', $variables['APP_DEBUG']);
            foreach (['APP_KEY', 'DB_PASSWORD', 'REDIS_PASSWORD', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'AWS_SECRET_ACCESS_KEY'] as $secret) {
                $this->assertSame('', $variables[$secret], $secret);
            }
            foreach ($variables as $name => $value) {
                if (str_ends_with($name, '_ENABLED')) {
                    $this->assertSame('false', $value, $name);
                }
            }
        }
    }

    private function consoleCommand(string $signature): PendingCommand
    {
        $command = $this->artisan($signature);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    private function configureProfile(): void
    {
        $settings = [
            'app.env' => 'staging', 'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)), 'app.cipher' => 'AES-256-CBC',
            'app.url' => 'https://api.ucg-staging.example.org',
            'operations.resource_namespace' => 'ucg_staging', 'operations.public_domain' => 'ucg-staging.example.org',
            'operations.secrets_source' => 'runtime', 'operations.data_classification' => 'synthetic',
            'cors.allowed_origins' => ['https://app.ucg-staging.example.org'], 'cors.allowed_origins_patterns' => [], 'cors.supports_credentials' => true,
            'database.default' => 'pgsql', 'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.url' => null, 'database.connections.pgsql.host' => 'no-network.invalid',
            'database.connections.pgsql.database' => 'ucg_staging', 'database.connections.pgsql.username' => 'ucg_staging_app', 'database.connections.pgsql.password' => 'sensitive-sentinel',
            'database.redis.options.prefix' => 'ucg_staging_redis_',
            'database.redis.default.url' => null, 'database.redis.cache.url' => null,
            'database.redis.default.password' => 'sensitive-sentinel', 'database.redis.cache.password' => 'sensitive-sentinel',
            'database.redis.default.database' => 0, 'database.redis.cache.database' => 1,
            'cache.default' => 'redis', 'cache.prefix' => 'ucg_staging_cache_', 'tenancy.cache_store' => 'redis', 'horizon.prefix' => 'ucg_staging_horizon:',
            'queue.default' => 'redis', 'queue.connections.redis.connection' => 'default', 'queue.connections.redis.retry_after' => 330, 'queue.connections.redis.after_commit' => true,
            'session.secure' => true, 'session.http_only' => true, 'session.encrypt' => true, 'session.same_site' => 'lax', 'session.cookie' => 'ucg_staging_session', 'session.domain' => '.ucg-staging.example.org',
            'sanctum.stateful' => ['app.ucg-staging.example.org', 'api.ucg-staging.example.org'],
            'reverb.apps.apps.0.app_id' => 'ucg_staging', 'reverb.apps.apps.0.key' => 'public-test-key', 'reverb.apps.apps.0.secret' => 'sensitive-sentinel', 'reverb.apps.apps.0.options.scheme' => 'https', 'reverb.apps.apps.0.options.useTLS' => true, 'reverb.apps.apps.0.options.host' => 'ws.ucg-staging.example.org', 'reverb.apps.apps.0.allowed_origins' => ['app.ucg-staging.example.org'], 'reverb.servers.reverb.scaling.channel' => 'ucg_staging_reverb',
            'filesystems.artifacts' => 'local', 'filesystems.disks.local.driver' => 'local', 'filesystems.disks.local.root' => '/srv/ucg_staging/storage/app/private', 'mail.default' => 'log',
        ];
        app(Repository::class)->set($settings);
    }
}
