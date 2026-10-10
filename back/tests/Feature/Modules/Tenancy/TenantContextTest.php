<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Contracts\Storage\ArtifactStorage;
use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Data\OrganizationDetailsData;
use App\Modules\Tenancy\Application\Services\OrganizationService;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\OrganizationStatus;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Jobs\StoreTenantArtifact;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_resolves_an_immutable_context_from_the_authenticated_route(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id, 'timezone' => 'Africa/Tunis']);
        $response = $this->actingAs($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context');
        $response->assertOk()->assertExactJson([
            'data' => [
                'organization_id' => $organization->id, 'actor_user_id' => $owner->id,
                'slug' => $organization->slug, 'timezone' => 'Africa/Tunis', 'language' => 'fr',
            ],
            'meta' => ['request_id' => $response->headers->get('X-Request-ID')],
        ]);
        $this->assertTrue(Str::isUuid($response->json('meta.request_id')));
        $this->assertNoContext();
    }

    public function test_authentication_runs_before_tenant_resolution_and_binding(): void
    {
        $this->getJson('/api/v1/tenants/'.Str::uuid().'/context')
            ->assertUnauthorized()->assertJsonPath('code', 'AUTHENTICATION_REQUIRED');
        $this->assertNoContext();
    }

    /** @return array<string, array{string}> */
    public static function inaccessibleIdentifiers(): array
    {
        return ['another owner' => ['foreign'], 'unknown UUID' => ['unknown'], 'malformed UUID' => ['malformed']];
    }

    #[DataProvider('inaccessibleIdentifiers')]
    public function test_returns_the_same_neutral_404_for_inaccessible_tenants(string $kind): void
    {
        $owner = User::factory()->create();
        $foreign = Organization::factory()->create();
        $id = match ($kind) {
            'foreign' => $foreign->id,
            'unknown' => (string) Str::uuid(),
            default => 'not-an-organization',
        };
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$id.'/context')
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND')
            ->assertJsonPath('message', __('api.errors.resource_not_found'))
            ->assertJsonMissing(['organization_id' => $foreign->id]);
        $this->assertNoContext();
    }

    public function test_missing_context_is_not_inferred_from_owned_organizations_payload_query_or_headers(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->actingAs($owner)->json('GET', '/api/v1/tenant/context?tenant='.$organization->id.'&organization_id='.$organization->id,
            ['tenant' => $organization->id, 'organization_id' => $organization->id],
            ['X-Tenant-ID' => $organization->id, 'X-Organization-ID' => $organization->id])
            ->assertUnprocessable()->assertJsonPath('code', 'TENANT_CONTEXT_REQUIRED')
            ->assertJsonPath('message', 'Un contexte d’organisation valide est requis pour cette opération.')
            ->assertJsonStructure(['message', 'code', 'errors', 'meta' => ['request_id']]);
        $this->assertNoContext();
    }

    public function test_an_exact_approved_domain_resolves_context_without_a_route_identifier(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.approved_domains' => ['ucg.example.test' => $organization->id]]);
        $this->actingAs($owner)->getJson('https://UCG.example.test/api/v1/tenant/context')
            ->assertOk()->assertJsonPath('data.organization_id', $organization->id)
            ->assertJsonPath('data.actor_user_id', $owner->id);
        $this->assertNoContext();
    }

    /** @return array<string, array{string}> */
    public static function unapprovedHosts(): array
    {
        return ['unknown' => ['unknown.example.test'], 'subdomain' => ['sub.ucg.example.test'], 'lookalike' => ['ucg.example.test.evil.test']];
    }

    #[DataProvider('unapprovedHosts')]
    public function test_unapproved_hosts_and_forwarded_headers_cannot_select_a_tenant(string $host): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.approved_domains' => ['ucg.example.test' => $organization->id, '*.example.test' => $organization->id]]);
        $this->actingAs($owner)->getJson('https://'.$host.'/api/v1/tenant/context', [
            'X-Forwarded-Host' => 'ucg.example.test', 'X-Tenant-ID' => $organization->id,
        ])->assertUnprocessable()->assertJsonPath('code', 'TENANT_CONTEXT_REQUIRED');
        $this->assertNoContext();
    }

    public function test_domain_approval_does_not_grant_access_to_another_owners_organization(): void
    {
        $organization = Organization::factory()->create();
        config(['tenancy.approved_domains' => ['ucg.example.test' => $organization->id]]);
        $this->actingAs(User::factory()->create())->getJson('https://ucg.example.test/api/v1/tenant/context')
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_context_endpoint_honors_a_policy_denial_even_after_successful_resolution(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        Gate::before(fn (): bool => false);
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')
            ->assertForbidden()->assertJsonPath('code', 'AUTHORIZATION_DENIED');
        $this->assertNoContext();
    }

    public function test_invalid_domain_configuration_cannot_fall_back_to_a_route(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.approved_domains' => 'untrusted']);
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->assertNoContext();
    }

    /** @return array<string, array{mixed}> */
    public static function invalidApprovals(): array
    {
        return ['invalid UUID' => ['not-a-uuid'], 'null mapping' => [null], 'array mapping' => [[]]];
    }

    #[DataProvider('invalidApprovals')]
    public function test_invalid_domain_approvals_fail_closed_even_with_an_authorized_route(mixed $approval): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.approved_domains' => ['ucg.example.test' => $approval]]);
        $this->actingAs($owner)->getJson('https://ucg.example.test/api/v1/tenants/'.$organization->id.'/context')
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
    }

    public function test_a_route_and_approved_domain_must_agree_even_when_the_actor_owns_both_tenants(): void
    {
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        config(['tenancy.approved_domains' => ['ucg.example.test' => $first->id]]);
        $this->actingAs($owner)->getJson('https://ucg.example.test/api/v1/tenants/'.$second->id.'/context')
            ->assertNotFound()->assertJsonPath('code', 'RESOURCE_NOT_FOUND');
        $this->getJson('https://ucg.example.test/api/v1/tenants/'.$first->id.'/context')
            ->assertOk()->assertJsonPath('data.organization_id', $first->id);
    }

    /** @return array<string, array{OrganizationStatus}> */
    public static function inactiveStatuses(): array
    {
        return ['suspended' => [OrganizationStatus::Suspended], 'closing' => [OrganizationStatus::Closing], 'archived' => [OrganizationStatus::Archived]];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_inactive_tenants_refuse_business_context_but_keep_owner_management_readable(OrganizationStatus $status): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id, 'status' => $status]);
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$organization->id.'/context')
            ->assertConflict()->assertJsonPath('code', 'ORGANIZATION_INACTIVE')
            ->assertJsonPath('message', 'Cette organisation n’est pas active. Les modifications ordinaires sont désactivées.');
        $this->getJson('/api/v1/organizations/'.$organization->id)->assertOk()->assertJsonPath('data.status', $status->value);
        $this->assertNoContext();
    }

    public function test_context_is_current_within_a_request_and_never_reused_after_success_or_failure(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $otherOwner->id]);
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->get('api/v1/tenants/{tenant}/context-probe', function (TenantContext $context): JsonResponse {
                $this->assertSame($context, app(TenantContext::class));
                $this->assertSame($context->organizationId, Context::get('tenant_id'));
                if (request()->boolean('fail')) {
                    abort(409);
                }

                return response()->json(['organization_id' => $context->organizationId, 'request_id' => $context->requestId]);
            });
        $firstResponse = $this->actingAs($owner)->getJson('/api/v1/tenants/'.$first->id.'/context-probe')
            ->assertOk()->assertJsonPath('organization_id', $first->id);
        $this->assertNoContext();
        $this->getJson('/api/v1/tenants/'.$first->id.'/context-probe?fail=1')->assertConflict();
        $this->assertNoContext();
        $secondResponse = $this->actingAs($otherOwner)->getJson('/api/v1/tenants/'.$second->id.'/context-probe')
            ->assertOk()->assertJsonPath('organization_id', $second->id);
        $this->assertNotSame($firstResponse->json('request_id'), $secondResponse->json('request_id'));
        $this->assertNoContext();
        $this->getJson('/api/v1/organizations')->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertNoContext();
    }

    public function test_tenant_resolution_precedes_business_binding_and_refuses_cross_context_identifiers(): void
    {
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $foreign = Organization::factory()->create();
        $binding = new class
        {
            public bool $reached = false;
        };
        Route::bind('context_resource', function (string $id) use ($binding): string {
            $binding->reached = true;
            app(TenantContext::class)->assertOrganization($id);

            return $id;
        });
        Route::middleware(['api', 'auth:sanctum', 'tenant'])
            ->get('api/v1/tenants/{tenant}/binding-probe/{context_resource}', fn (): JsonResponse => response()->json(['bound' => true]));
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$foreign->id.'/binding-probe/'.$foreign->id)->assertNotFound();
        $this->assertFalse($binding->reached);
        $this->getJson('/api/v1/tenants/'.$first->id.'/binding-probe/'.$second->id)->assertNotFound();
        $this->assertTrue($binding->reached);
        $this->getJson('/api/v1/tenants/'.$first->id.'/binding-probe/'.$first->id)->assertOk()->assertJsonPath('bound', true);
        $this->assertNoContext();
    }

    public function test_parameter_and_header_substitution_cannot_redirect_a_write(): void
    {
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $this->actingAs($owner)->putJson('/api/v1/organizations/'.$first->id.'?tenant='.$second->id, [
            'name' => 'Paramètres autorisés', 'timezone' => 'UTC', 'language' => 'fr', 'country' => 'FR',
            'settings' => ['week_starts_on' => 7, 'date_format' => 'Y-m-d'],
        ], ['X-Tenant-ID' => $second->id])->assertOk()->assertJsonPath('data.id', $first->id);
        $this->assertDatabaseHas('organizations', ['id' => $first->id, 'name' => 'Paramètres autorisés']);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $first->id, 'week_starts_on' => '7']);
        $this->assertDatabaseHas('organizations', ['id' => $second->id, 'name' => $second->name]);
        $this->assertDatabaseHas('organization_settings', ['organization_id' => $second->id, 'week_starts_on' => '1']);
        $this->assertNoContext();
    }

    public function test_service_rechecks_status_under_lock_instead_of_trusting_a_stale_snapshot(): void
    {
        $owner = User::factory()->create();
        $organization = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $context = app(TenantResolver::class)->resolve($organization->id, 'localhost', $owner->id, (string) Str::uuid());
        $organization->status = OrganizationStatus::Suspended;
        $organization->save();
        try {
            app(OrganizationService::class)->updateInContext($context, new OrganizationDetailsData('Interdit', 'UTC', 'fr', 'FR', 1, 'd/m/Y'));
            $this->fail('A stale active context must not authorize a write.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('ORGANIZATION_INACTIVE', $exception->errorCode());
        }
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => $organization->name, 'status' => 'suspended']);
    }

    public function test_service_rechecks_the_context_actor_before_a_write(): void
    {
        $organization = Organization::factory()->create();
        $other = User::factory()->create();
        $context = new TenantContext($organization->id, $other->id, $organization->slug, 'UTC', 'fr', (string) Str::uuid());
        try {
            app(OrganizationService::class)->updateInContext($context, new OrganizationDetailsData('Interdit', 'UTC', 'fr', 'FR', 1, 'd/m/Y'));
            $this->fail('Another actor must not authorize a write.');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'name' => $organization->name]);
        }
    }

    public function test_job_context_is_explicitly_serializable_and_independent_of_the_next_http_request(): void
    {
        $owner = User::factory()->create();
        $first = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $second = Organization::factory()->create(['owner_user_id' => $owner->id]);
        $requestId = (string) Str::uuid();
        $context = app(TenantResolver::class)->resolve($first->id, 'localhost', $owner->id, $requestId);
        $job = unserialize(serialize(new StoreTenantArtifact($context->toJobContext(), 'exports/probe.txt', 'Tenant A')));
        $this->assertInstanceOf(StoreTenantArtifact::class, $job);
        $this->actingAs($owner)->getJson('/api/v1/tenants/'.$second->id.'/context')->assertOk();
        Storage::fake('local');
        $job->handle(app(ArtifactStorage::class));
        Storage::disk('local')->assertExists('tenants/'.$first->id.'/exports/probe.txt');
        Storage::disk('local')->assertMissing('tenants/'.$second->id.'/exports/probe.txt');
        $this->assertSame('Tenant A', Storage::disk('local')->get('tenants/'.$first->id.'/exports/probe.txt'));
        $this->assertSame(['request:'.$requestId, 'tenant:'.$first->id], $job->tags());
        $this->assertNoContext();
    }

    private function assertNoContext(): void
    {
        $this->assertNull(Context::get('tenant_id'));
        $this->assertNull(request()->attributes->get(TenantContext::ATTRIBUTE));
        try {
            app(TenantContext::class);
            $this->fail('No tenant context may be resolved outside a tenant request.');
        } catch (TenantContextRequiredException $exception) {
            $this->assertSame('TENANT_CONTEXT_REQUIRED', $exception->errorCode());
        }
    }
}
