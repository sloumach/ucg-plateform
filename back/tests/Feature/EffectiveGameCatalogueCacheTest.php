<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class EffectiveGameCatalogueCacheTest extends TestCase
{
    // A transaction would bypass snapshot caching and hide this regression.
    use AuthenticatesWithMfa, DatabaseMigrations, ProvidesPeopleAccess {
        runDatabaseMigrations as private runCacheDatabaseMigrations;
    }

    public function runDatabaseMigrations(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->runCacheDatabaseMigrations();

            return;
        }

        // PostgreSQL migrate:fresh retains trigger functions; never rebuild a shared suite database here.
        $original = DB::getDefaultConnection();
        $configuration = config('database.connections.'.$original);
        $admin = DB::connection()->getPdo();
        $database = 'ucg_catalogue_cache_'.bin2hex(random_bytes(12));
        $migrated = RefreshDatabaseState::$migrated;
        $admin->exec('CREATE DATABASE "'.$database.'"');
        config(['database.connections.catalogue_cache_test' => [...$configuration, 'url' => null, 'database' => $database]]);
        DB::setDefaultConnection('catalogue_cache_test');
        $cleanup = static function () use ($original, $admin, $database, $migrated): void {
            DB::setDefaultConnection($original);
            DB::purge('catalogue_cache_test');
            RefreshDatabaseState::$migrated = $migrated;
            $admin->exec('DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
        };
        try {
            $this->runCacheDatabaseMigrations();
        } catch (\Throwable $exception) {
            $cleanup();

            throw $exception;
        }
        $this->beforeApplicationDestroyed($cleanup);
    }

    public function test_warm_database_cache_keeps_profile_catalogue_readable_without_deserializing_models(): void
    {
        config(['cache.default' => 'database', 'cache.serializable_classes' => false]);
        app()->forgetInstance('cache.store');
        $organization = Organization::factory()->create();
        $user = User::factory()->withMfa()->create();
        $this->grantPeopleAccess($organization, $user, ['sports.read']);
        $this->actingAsWithMfa($user);
        $path = '/api/v1/tenants/'.$organization->id.'/game-profile-catalogue';

        $this->getJson($path)->assertOk()->assertJsonFragment(['key' => 'valorant']);
        $this->getJson($path)->assertOk()->assertJsonFragment(['key' => 'valorant']);

        $this->assertDatabaseCount('people', 0);
    }
}
