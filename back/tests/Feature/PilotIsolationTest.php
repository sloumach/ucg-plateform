<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PilotIsolationTest extends TestCase
{
    public function test_pilot_child_discards_inherited_database_and_cached_configuration_without_parent_queries(): void
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
