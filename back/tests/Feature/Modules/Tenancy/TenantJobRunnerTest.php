<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Modules\Tenancy\Application\Contracts\TenantCache;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Contracts\TenantExecutionContext;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Application\Services\TenantJobRunner;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Exceptions\TenantContextRequiredException;
use App\Modules\Tenancy\Domain\Exceptions\TenantExecutionRejectedException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Support\Queue\JobContext;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\Jobs\TenantContextProbe;
use Tests\TestCase;

class TenantJobRunnerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_serialized_transport_uses_its_tenant_and_actor_not_ambient_context(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $context = $this->context($organization);
        $transport = unserialize(serialize($context->toJobContext()));
        Context::add('tenant_id', $other->id);

        $result = app(TenantJobRunner::class)->user($transport, function (): TenantContext {
            $resolved = app(TenantContext::class);
            $this->assertSame($resolved->organizationId, Context::get('tenant_id'));

            return $resolved;
        });

        $this->assertSame($organization->id, $result->organizationId);
        $this->assertSame($organization->owner_user_id, $result->actorUserId);
        $this->assertSame($context->requestId, $result->requestId);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertSame($other->id, Context::get('tenant_id'));
        Context::forget('tenant_id');
    }

    public function test_current_roles_are_reloaded_after_dispatch(): void
    {
        $membership = OrganizationMembership::factory()->administrator()->create();
        $context = app(TenantResolver::class)->resolve($membership->organization_id, '', $membership->user_id, (string) Str::uuid());
        $membership->roles = ['member'];
        $membership->save();

        $roles = app(TenantJobRunner::class)->user($context->toJobContext(), fn () => app(TenantContext::class)->roles);

        $this->assertSame(['member'], $roles);
    }

    public function test_old_serialized_context_without_actor_fails_closed_without_a_property_error(): void
    {
        $uuid = (string) Str::uuid();
        $payload = 'O:'.strlen(JobContext::class).':"'.JobContext::class.'":2:{s:9:"requestId";s:36:"'.$uuid.'";s:8:"tenantId";s:36:"'.$uuid.'";}';
        $transport = unserialize($payload);

        try {
            app(TenantJobRunner::class)->user($transport, fn () => 'must not execute');
            $this->fail('Legacy payloads must not acquire implicit actor privileges.');
        } catch (TenantExecutionRejectedException $exception) {
            $this->assertSame('ACTOR_REQUIRED', $exception->reason);
        }
    }

    public function test_revoked_membership_prevents_execution_before_any_side_effect(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $context = app(TenantResolver::class)->resolve($membership->organization_id, '', $membership->user_id, (string) Str::uuid());
        DB::table('organization_memberships')->where('id', $membership->id)->update(['status' => 'revoked']);
        $called = false;

        try {
            app(TenantJobRunner::class)->user($context->toJobContext(), function () use (&$called): void {
                $called = true;
            });
            $this->fail('Revoked access must refuse queued work.');
        } catch (TenantExecutionRejectedException $exception) {
            $this->assertSame('TENANT_ACCESS_REVOKED', $exception->reason);
        }

        $this->assertFalse($called);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    /** @return array<string, array{string}> */
    public static function inactiveStatuses(): array
    {
        return ['suspended' => ['suspended'], 'closing' => ['closing'], 'archived' => ['archived']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_inactive_tenant_refuses_work_after_dispatch(string $status): void
    {
        $organization = Organization::factory()->create();
        $context = $this->context($organization);
        DB::table('organizations')->where('id', $organization->id)->update(['status' => $status]);

        $this->expectException(TenantExecutionRejectedException::class);
        app(TenantJobRunner::class)->user($context->toJobContext(), fn () => 'must not execute');
    }

    /** @return array<string, array{?string, string, ?int}> */
    public static function invalidTransport(): array
    {
        $uuid = '019caabc-1234-7000-8000-000000000001';

        return ['missing tenant' => [null, $uuid, 1], 'invalid tenant' => ['tenant-456', $uuid, 1],
            'invalid correlation' => [$uuid, 'request-123', 1], 'missing actor' => [$uuid, $uuid, null],
            'negative actor' => [$uuid, $uuid, -1]];
    }

    #[DataProvider('invalidTransport')]
    public function test_invalid_transport_cannot_fall_back_to_an_ambient_tenant(?string $tenant, string $request, ?int $actor): void
    {
        $this->expectException(TenantExecutionRejectedException::class);
        app(TenantJobRunner::class)->user(new JobContext($request, $tenant, $actor), fn () => 'must not execute');
    }

    public function test_exception_cleanup_and_nested_work_do_not_leak_between_tenants(): void
    {
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $runner = app(TenantJobRunner::class);
        $a = $this->context($first)->toJobContext();
        $b = $this->context($second)->toJobContext();

        try {
            $runner->user($a, function () use ($runner, $b, $first, $second): void {
                $this->assertSame($second->id, $runner->user($b, fn () => app(TenantContext::class)->organizationId));
                $this->assertSame($first->id, app(TenantContext::class)->organizationId);
                if (app(TenantContext::class)->organizationId === $first->id) {
                    throw new RuntimeException('Controlled failure.');
                }
            });
            $this->fail('The exception must propagate to the queue retry policy.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Controlled failure.', $exception->getMessage());
        }

        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertNull(Context::get('tenant_id'));
        $this->expectException(TenantContextRequiredException::class);
        app(TenantContext::class);
    }

    public function test_system_scope_has_no_user_permissions_even_with_an_ambient_http_context(): void
    {
        $organization = Organization::factory()->create();
        $other = Organization::factory()->create();
        $http = $this->context($other);
        request()->attributes->set(TenantContext::ATTRIBUTE, $http);
        $run = (string) Str::uuid();

        app(TenantJobRunner::class)->scheduled(new JobContext($run, $organization->id), function () use ($organization): void {
            $this->assertSame($organization->id, app(TenantExecutionContext::class)->organizationId);
            $this->assertNull(app(TenantExecutionContext::class)->actorContext);
            try {
                app(TenantContext::class);
                $this->fail('System work must not impersonate an ambient user.');
            } catch (TenantContextRequiredException) {
                $this->assertTrue(app(TenantExecutionScope::class)->active());
            }
        });

        $this->assertFalse(app(TenantExecutionScope::class)->active());
        request()->attributes->remove(TenantContext::ATTRIBUTE);
    }

    public function test_database_queue_delay_and_retry_keep_the_original_tenant_in_a_reused_worker(): void
    {
        $this->freezeTime();
        config(['tenancy.cache_store' => 'database']);
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $a = $this->context($first);
        $b = $this->context($second);
        app(Dispatcher::class)->dispatch((new TenantContextProbe($a->toJobContext(), 'first', true))->onConnection('database')->delay(10));
        app(Dispatcher::class)->dispatch((new TenantContextProbe($b->toJobContext(), 'second'))->onConnection('database')->delay(10));
        $this->assertDatabaseCount('jobs', 2);
        $this->assertNull(app(QueueManager::class)->connection('database')->pop('default'));
        $this->travel(10)->seconds();

        Exceptions::fake();
        $this->processNext();
        Exceptions::assertReported(RuntimeException::class);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->processNext();
        $this->travel(5)->seconds();
        $this->processNext();

        $cache = app(TenantCache::class);
        $this->assertSame('first', $cache->get($a, 'probe:123'));
        $this->assertSame('second', $cache->get($b, 'probe:123'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_database_queue_marks_inactive_work_failed_once_and_continues_other_tenants(): void
    {
        config(['tenancy.cache_store' => 'database']);
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $a = $this->context($first);
        $b = $this->context($second);
        $bus = app(Dispatcher::class);
        $bus->dispatch((new TenantContextProbe($a->toJobContext()))->onConnection('database'));
        $bus->dispatch((new TenantContextProbe($b->toJobContext(), 'second'))->onConnection('database'));
        DB::table('organizations')->where('id', $first->id)->update(['status' => 'suspended']);

        $this->processNext();
        $this->processNext();

        $this->assertSame('second', app(TenantCache::class)->get($b, 'probe:123'));
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_queue_permission_denial_is_terminal_after_role_downgrade(): void
    {
        $membership = OrganizationMembership::factory()->administrator()->create();
        $context = app(TenantResolver::class)->resolve($membership->organization_id, '', $membership->user_id, (string) Str::uuid());
        app(Dispatcher::class)->dispatch((new TenantContextProbe($context->toJobContext(), requiresAdministration: true))->onConnection('database'));
        $membership->roles = ['member'];
        $membership->save();

        $this->processNext();

        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertNull(app(TenantCache::class)->get(app(TenantResolver::class)->resolve($membership->organization_id, '',
            $membership->user_id, $context->requestId), 'probe:123'));
    }

    public function test_operator_retry_revalidates_access_and_keeps_the_original_tenant(): void
    {
        config(['tenancy.cache_store' => 'database']);
        $first = Organization::factory()->create();
        $second = Organization::factory()->create();
        $a = $this->context($first);
        $b = $this->context($second);
        app(Dispatcher::class)->dispatch((new TenantContextProbe($a->toJobContext(), 'retried'))->onConnection('database'));
        DB::table('organizations')->where('id', $first->id)->update(['status' => 'suspended']);
        $this->processNext();
        $failedId = DB::table('failed_jobs')->value('uuid');
        $this->assertNotNull($failedId);
        DB::table('organizations')->where('id', $first->id)->update(['status' => 'active']);

        $this->assertSame(0, Artisan::call('queue:retry', ['id' => [$failedId]]));
        $this->processNext();

        $cache = app(TenantCache::class);
        $this->assertSame('retried', $cache->get($a, 'probe:123'));
        $this->assertNull($cache->get($b, 'probe:123'));
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_system_execution_cannot_accept_a_user_actor(): void
    {
        $this->expectException(TenantExecutionRejectedException::class);
        app(TenantJobRunner::class)->scheduled(new JobContext((string) Str::uuid(), (string) Str::uuid(), 1), fn () => 'must not execute');
    }

    private function context(Organization $organization): TenantContext
    {
        return app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
    }

    private function processNext(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'default', '--once' => true, '--sleep' => 0]));
    }
}
