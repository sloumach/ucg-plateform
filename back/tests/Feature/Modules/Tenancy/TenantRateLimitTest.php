<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantRateLimitTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_returns_localized_429_with_retry_headers_only_for_the_exhausted_tenant(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database']);
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.limit_overrides.'.$first->id => ['requests_per_minute' => 1]]);
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$first->id.'/context')->assertOk()->assertHeader('X-RateLimit-Remaining', '0');

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
        $this->actingAs($intruder)->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertNotFound();

        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')->assertOk();
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

        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')
            ->assertInternalServerError()->assertJsonPath('code', 'INTERNAL_ERROR');
    }
}
