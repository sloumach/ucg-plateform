<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TenantRateLimitConcurrencyTest extends TestCase
{
    /** @return array<string, array{int, int, bool}> */
    public static function budgets(): array
    {
        return ['one slot, two requests' => [1, 2, false], 'two slots, four requests' => [2, 4, false],
            'one slot per tenant' => [1, 2, true]];
    }

    #[DataProvider('budgets')]
    public function test_independent_admissions_never_exceed_the_tenant_budget(int $maximum, int $requests, bool $differentTenants): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent database rate admission requires PostgreSQL.');
        }
        $original = DB::getDefaultConnection();
        $config = config('database.connections.pgsql');
        $admin = DB::connection()->getPdo();
        $database = 'ucg_rate_race_'.bin2hex(random_bytes(6));
        $barrier = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ucg_rate_go_'.bin2hex(random_bytes(12));
        $workers = [];
        try {
            $admin->exec('CREATE DATABASE "'.$database.'"');
            config(['database.connections.rate_race' => [...$config, 'url' => null, 'database' => $database], 'tenancy.cache_store' => 'database']);
            DB::setDefaultConnection('rate_race');
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]));
            $owner = User::factory()->withMfa()->create();
            $alpha = Organization::factory()->create(['owner_user_id' => $owner->id]);
            $beta = Organization::factory()->create(['owner_user_id' => $owner->id]);
            $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => '',
                'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
            for ($index = 0; $index < $requests; $index++) {
                $payload = ['org' => $differentTenants && $index === 1 ? $beta->id : $alpha->id, 'actor' => $owner->id, 'maximum' => $maximum];
                $worker = new Process([PHP_BINARY, base_path('tests/Fixtures/tenant-rate-worker.php'), json_encode($payload, JSON_THROW_ON_ERROR), $barrier], base_path(), $environment, null, 60);
                $worker->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 20;
            do {
                $ready = true;
                foreach ($workers as $worker) {
                    if (! str_contains($worker->getOutput(), 'READY')) {
                        if (! $worker->isRunning()) {
                            $this->fail($worker->getErrorOutput().$worker->getOutput());
                        }
                        $ready = false;
                    }
                }
                if ($ready) {
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $this->fail('All rate workers must reach the admission barrier.');
                }
                usleep(10000);
            } while (true);
            $this->assertTrue(touch($barrier));
            $statuses = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                if (preg_match('/RESULT:(.*)/', $worker->getOutput(), $match) !== 1) {
                    $this->fail($worker->getOutput());
                }
                $result = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
                $this->assertSame($result['status'] === 429 ? 'quota' : null, $result['reason']);
            }
            sort($statuses);
            $expected = $differentTenants ? [200, 200] : [...array_fill(0, $maximum, 200), ...array_fill(0, $requests - $maximum, 429)];
            $this->assertSame($expected, $statuses);
            $limiter = new RateLimiter(app(TenantCacheStore::class)->repository());
            $this->assertSame($maximum, $limiter->attempts(TenantResourceNamespace::key($alpha->id, 'rate', 'http')));
            $this->assertSame($differentTenants ? 1 : 0, $limiter->attempts(TenantResourceNamespace::key($beta->id, 'rate', 'http')));
            $this->assertDatabaseCount('cache_locks', 0);
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::setDefaultConnection($original);
            DB::purge('rate_race');
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
            if (is_file($barrier)) {
                unlink($barrier);
            }
        }

    }
}
