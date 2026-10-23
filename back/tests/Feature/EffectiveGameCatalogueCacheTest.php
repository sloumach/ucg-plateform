<?php

namespace Tests\Feature;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class EffectiveGameCatalogueCacheTest extends TestCase
{
    // A transaction would bypass snapshot caching and hide this regression.
    use AuthenticatesWithMfa, DatabaseMigrations, ProvidesPeopleAccess;

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
