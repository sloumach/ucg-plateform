<?php

namespace Tests\Feature\Modules\Tenancy;

use Closure;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RetentionSqliteBootstrapTest extends TestCase
{
    public function test_composer_package_discovery_succeeds_without_creating_or_opening_a_database(): void
    {
        $relative = 'storage/framework/testing/sqlite-bootstrap-'.bin2hex(random_bytes(8));
        $directory = base_path($relative);
        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(static function () use ($directory): void {
            $actual = realpath($directory);
            $parent = realpath(base_path('storage/framework/testing'));
            if ($actual === false || $parent === false || dirname($actual) !== $parent || ! str_starts_with(basename($actual), 'sqlite-bootstrap-')) {
                throw new RuntimeException('Unexpected bootstrap fixture cleanup path.');
            }
            File::deleteDirectory($actual);
        });
        $database = $directory.DIRECTORY_SEPARATOR.'not-created.sqlite';
        $process = new Process([PHP_BINARY, 'artisan', 'package:discover', '--no-interaction'], base_path(), [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => '',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'database',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'stderr',
            'APP_CONFIG_CACHE' => $relative.'/config.php', 'APP_PACKAGES_CACHE' => $relative.'/packages.php',
            'APP_SERVICES_CACHE' => $relative.'/services.php', 'APP_ROUTES_CACHE' => $relative.'/routes.php',
            'APP_EVENTS_CACHE' => $relative.'/events.php', 'TELESCOPE_ENABLED' => 'false', 'PULSE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ], null, 60);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        $this->assertFileDoesNotExist($database);
        $this->assertFileExists($directory.DIRECTORY_SEPARATOR.'packages.php');
    }

    public function test_resolving_a_connection_to_a_missing_database_stays_lazy(): void
    {
        $database = storage_path('framework/testing/missing-'.bin2hex(random_bytes(8)).'.sqlite');
        config(['database.connections.retention_lazy' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '']]);
        $connection = DB::connection('retention_lazy');

        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $this->assertFileDoesNotExist($database);
        $this->expectException(SQLiteDatabaseDoesNotExistException::class);
        $connection->getPdo();
    }

    public function test_raw_read_and_write_pdos_receive_the_denial_function_on_first_open(): void
    {
        config(['database.connections.retention_split' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'read' => ['database' => ':memory:'], 'write' => ['database' => ':memory:'],
        ]]);
        $connection = DB::connection('retention_split');
        $this->assertInstanceOf(Closure::class, $connection->getRawPdo());
        $this->assertInstanceOf(Closure::class, $connection->getRawReadPdo());
        $write = $connection->getPdo();
        $read = $connection->getReadPdo();

        $this->assertNotSame($write, $read);
        $this->assertSame(0, $this->permission($write));
        $this->assertSame(0, $this->permission($read));
    }

    public function test_queries_preserve_a_temporary_permission_but_reconnection_starts_with_denial(): void
    {
        config(['database.connections.retention_reconnect' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $connection = DB::connection('retention_reconnect');
        $original = $connection->getPdo();
        $original->sqliteCreateFunction('ucg_manual_erasure_allowed', static fn (): int => 1, 4);
        try {
            $this->assertSame(1, $connection->selectOne("SELECT ucg_manual_erasure_allowed('people', '{}', NULL, 'DELETE') AS allowed")->allowed);
            $this->assertSame(1, $this->permission($original));
            DB::disconnect('retention_reconnect');
            DB::reconnect('retention_reconnect');
            $reopened = $connection->getPdo();
            $this->assertNotSame($original, $reopened);
            $this->assertSame(0, $this->permission($reopened));
        } finally {
            $original->sqliteCreateFunction('ucg_manual_erasure_allowed', static fn (): int => 0, 4);
        }
    }

    private function permission(PDO $pdo): mixed
    {
        $statement = $pdo->query("SELECT ucg_manual_erasure_allowed('people', '{}', NULL, 'DELETE')");
        if ($statement === false) {
            throw new RuntimeException('The SQLite denial function must exist before the first raw query.');
        }

        return $statement->fetchColumn();
    }
}
