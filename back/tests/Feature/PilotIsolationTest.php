<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PilotIsolationTest extends TestCase
{
    #[TestWith(['localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1'])]
    #[TestWith(['foreign.example.test'])]
    public function test_pilot_child_discards_inherited_database_cached_configuration_and_frontend_without_parent_queries(string $statefulDomains): void
    {
        $cache = tempnam(sys_get_temp_dir(), 'ucg-pilot-');
        $this->assertIsString($cache);
        $database = $cache.'.sqlite';
        file_put_contents($cache, '<?php throw new RuntimeException("FICTIF inherited configuration executed");');
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        try {
            $process = new Process([PHP_BINARY, base_path('vendor/bin/phpunit'), '--configuration', 'phpunit-pilot.xml',
                '--testsuite', 'REL-001 fictitious pilot week', '--no-progress', '--colors=never'], base_path(), [
                    'APP_ENV' => 'production', 'APP_CONFIG_CACHE' => $cache, 'APP_KEY' => 'FICTIF-invalid-key',
                    'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'DB_URL' => 'pgsql://invalid:invalid@invalid.example.test/invalid',
                    'CACHE_STORE' => 'redis', 'TENANCY_CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis',
                    'QUEUE_CONNECTION' => 'redis', 'MAIL_MAILER' => 'smtp', 'BROADCAST_CONNECTION' => 'reverb',
                    'APP_URL' => 'https://foreign.example.test', 'FRONTEND_URL' => 'https://foreign.example.test',
                    'SANCTUM_STATEFUL_DOMAINS' => $statefulDomains,
                ]);
            $process->setTimeout(120);
            $process->run();

            $this->assertTrue($process->isSuccessful(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame(0, $queries);
            $this->assertFileDoesNotExist($database);
        } finally {
            unlink($cache);
        }
    }
}
