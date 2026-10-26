<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Support\Cache\TenantCacheStore;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\TestCase;

class TenantRateLimitTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase;

    public function test_returns_localized_429_with_retry_headers_only_for_the_exhausted_tenant(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database']);
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.limit_overrides.'.$first->id => ['requests_per_minute' => 1]]);
        $this->actingAsWithMfa($owner)->getJson('/api/v1/tenants/'.$first->id.'/context')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');

        $response = $this->getJson('/api/v1/tenants/'.$first->id.'/context');

        $response->assertTooManyRequests()->assertHeader('Retry-After', '60')
            ->assertJsonPath('code', 'RATE_LIMIT_EXCEEDED')
            ->assertJsonPath('message', 'Trop de tentatives. Veuillez réessayer plus tard.')
            ->assertJsonStructure(['message', 'code', 'errors', 'meta' => ['request_id']]);
        $this->getJson('/api/v1/tenants/'.$second->id.'/context')->assertOk();
        $this->travel(61)->seconds();
        $this->getJson('/api/v1/tenants/'.$first->id.'/context')->assertOk();
    }

    public function test_unauthorized_requests_do_not_consume_another_organizations_budget(): void
    {
        config(['tenancy.limits.requests_per_minute' => 1]);
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $intruder = User::factory()->create();
        $this->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertUnauthorized();
        $this->actingAsWithMfa($intruder)->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertNotFound();

        $this->actingAsWithMfa($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertOk();
    }

    public function test_resource_limit_exception_uses_the_existing_api_feedback_contract_and_429(): void
    {
        Route::get('/api/testing/tenant-limit', fn () => throw new TenantLimitExceededException);

        $this->getJson('/api/testing/tenant-limit')
            ->assertTooManyRequests()->assertJsonPath('code', 'TENANT_LIMIT_EXCEEDED')
            ->assertJsonPath('message', 'La limite technique de cette organisation est atteinte.')
            ->assertJsonStructure(['meta' => ['request_id']])->assertJsonMissing(['trace']);
    }

    public function test_misconfigured_failover_limiter_fails_closed_before_the_action(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.cache_store' => 'failover']);

        $this->actingAsWithMfa($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')
            ->assertInternalServerError()->assertJsonPath('code', 'INTERNAL_ERROR');
    }

    public function test_short_lock_contention_is_retried_without_losing_the_rate_budget(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database', 'tenancy.limits.requests_per_minute' => 1]);
        $org = Organization::factory()->withMfaOwner()->create();
        $held = app(TenantCacheStore::class)->lock(TenantResourceNamespace::key($org->id, 'lock', 'http-rate'), 5);
        $this->assertTrue($held->get());
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $held->release());
        try {
            $this->actingAsWithMfa(User::findOrFail($org->owner_user_id))->getJson('/api/v1/tenants/'.$org->id.'/context')
                ->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
            Sleep::assertSleptTimes(1);
            $this->getJson('/api/v1/tenants/'.$org->id.'/context')->assertTooManyRequests();
        } finally {
            $held->release();
            Sleep::fake(false);
        }
    }

    public function test_persistent_contention_is_bounded_preserves_the_budget_and_does_not_block_beta(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database', 'tenancy.limits.requests_per_minute' => 1]);
        $owner = User::factory()->withMfa()->create();
        $alpha = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $beta = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $held = app(TenantCacheStore::class)->lock(TenantResourceNamespace::key($alpha->id, 'lock', 'http-rate'), 5);
        $this->assertTrue($held->get());
        Sleep::fake();
        try {
            $this->actingAsWithMfa($owner)->getJson('/api/v1/tenants/'.$alpha->id.'/context')
                ->assertTooManyRequests()->assertHeader('Retry-After', '1');
            Sleep::assertSleptTimes(10);
            $this->getJson('/api/v1/tenants/'.$beta->id.'/context')->assertOk();
            $held->release();
            $this->getJson('/api/v1/tenants/'.$alpha->id.'/context')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');
            $this->getJson('/api/v1/tenants/'.$alpha->id.'/context')->assertTooManyRequests();
        } finally {
            $held->release();
            Sleep::fake(false);
        }
    }
}
