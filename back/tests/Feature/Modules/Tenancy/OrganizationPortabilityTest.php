<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Data\OrganizationAuditData;
use App\Modules\Tenancy\Application\Services\OrganizationPortabilityService;
use App\Modules\Tenancy\Application\Services\TenantExecutionScope;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Contracts\PortabilityRepository;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\Organization;
use App\Modules\Tenancy\Domain\Models\OrganizationMembership;
use App\Modules\Tenancy\Infrastructure\Queue\BuildOrganizationExport;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class OrganizationPortabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['tenancy.cache_store' => 'database', 'tenancy.schedule_connection' => 'database', 'filesystems.artifacts' => 'local']);
    }

    #[Group('tenant-isolation')]
    public function test_real_database_queue_exports_only_target_rows_and_files_without_global_identity_secrets(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $owner = User::factory()->withMfa()->create();
        $first = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        $second = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        OrganizationMembership::factory()->withMfaAccount()->for($first)->create();
        OrganizationMembership::factory()->withMfaAccount()->for($second)->create();
        $resolver = app(TenantResolver::class);
        $a = $resolver->resolve($first->id, '', $owner->id, (string) Str::uuid());
        $b = $resolver->resolve($second->id, '', $owner->id, (string) Str::uuid());
        $files = app(TenantArtifacts::class);
        $firstFile = $files->store($a, 'same.txt', 'first-tenant-file');
        $secondFile = $files->store($b, 'same.txt', 'second-tenant-file');

        $operation = $this->build($first);
        $stream = app(OrganizationPortabilityService::class)->read($first->id, $operation->id, $this->audit($first));
        $contents = stream_get_contents($stream);
        fclose($stream);

        $this->assertIsString($contents);
        $this->assertSame('ready', $operation->status);
        $this->assertSame(['rows' => 7, 'files' => 1, 'format' => 'ucg-tenant-ndjson-v1',
            'organization_status' => 'active', 'organization_updated_at' => $first->updated_at?->format('Y-m-d\TH:i:s.uP')], $operation->details);
        $this->assertSame(hash('sha256', $contents), $operation->sha256);
        $this->assertStringContainsString($firstFile->id, $contents);
        $this->assertStringContainsString(base64_encode('first-tenant-file'), $contents);
        $this->assertStringNotContainsString($second->id, $contents);
        $this->assertStringNotContainsString($secondFile->id, $contents);
        $this->assertStringNotContainsString(base64_encode('second-tenant-file'), $contents);
        $this->assertStringNotContainsString($owner->password, $contents);
        $this->assertStringNotContainsString('"table":"users"', $contents);
        $this->assertSame('second-tenant-file', $files->read($b, $secondFile->id));
        $this->assertFalse(app(TenantExecutionScope::class)->active());
        $this->assertDatabaseCount('jobs', 0);
    }

    /** @return array<string, array{string}> */
    public static function inactiveStatuses(): array
    {
        return ['suspended' => ['suspended'], 'closing' => ['closing'], 'archived' => ['archived']];
    }

    #[DataProvider('inactiveStatuses')]
    public function test_operator_export_accepts_inactive_tenant_without_resuming_business_operations(string $status): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create(['status' => $status]);

        $operation = $this->build($organization);

        $this->assertSame('ready', $operation->status);
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'status' => $status]);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    #[Group('tenant-isolation')]
    public function test_serialized_export_is_idempotent_and_keeps_its_registered_tenant(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $operation = $this->build($organization);
        $before = Storage::disk('local')->get($this->path($operation));
        $job = unserialize(serialize(new BuildOrganizationExport($organization->id, $operation->id)));
        $this->assertInstanceOf(BuildOrganizationExport::class, $job);

        app()->call([$job, 'handle']);

        $this->assertSame($before, Storage::disk('local')->get($this->path($operation)));
        $this->assertDatabaseCount('tenant_portability_operations', 1);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    #[Group('tenant-isolation')]
    public function test_other_tenant_cannot_inspect_or_read_an_export_even_with_same_owner(): void
    {
        Storage::fake('local');
        $owner = User::factory()->withMfa()->create();
        $first = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        $second = Organization::factory()->withMfaOwner()->recycle($owner)->create();
        $operation = $this->build($first);

        $this->expectException(ModelNotFoundException::class);
        app(OrganizationPortabilityService::class)->read($second->id, $operation->id, $this->audit($second));
    }

    public function test_archive_expires_before_cleanup_and_cleanup_never_deletes_business_files(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $artifact = app(TenantArtifacts::class)->store($context, 'keep.txt', 'keep-me');
        $operation = $this->build($organization);
        $this->travel(24)->hours();
        try {
            app(OrganizationPortabilityService::class)->read($organization->id, $operation->id, $this->audit($organization));
            $this->fail('Expired archive must not be served before cleanup.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('EXPORT_EXPIRED', $exception->errorCode());
        }

        $this->assertSame(0, app(OrganizationPortabilityService::class)->expireBatch());
        app(OrganizationPortabilityService::class)->expire($organization->id, $operation->id);

        Storage::disk('local')->assertMissing($this->path($operation));
        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'expired', 'bytes' => 0]);
        $this->assertSame('keep-me', app(TenantArtifacts::class)->read($context, $artifact->id));
        $this->assertDatabaseCount('organization_settings', 1);
    }

    public function test_archive_tampering_is_refused_by_integrity_check(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $operation = $this->build($organization);
        Storage::disk('local')->put($this->path($operation), 'tampered');

        $this->expectException(ArtifactStorageException::class);
        app(OrganizationPortabilityService::class)->read($organization->id, $operation->id, $this->audit($organization));
    }

    public function test_row_limit_fails_the_queued_export_without_partial_ready_archive(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['tenancy.limits.export_rows' => 1]);
        $operation = app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));

        $this->work();

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'failed']);
        $this->assertDatabaseCount('failed_jobs', 1);
        Storage::disk('local')->assertMissing($this->path($operation));
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_unregistered_table_refuses_export_instead_of_silently_omitting_data(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        Queue::fake([BuildOrganizationExport::class]);
        Schema::create('unregistered_tenant_data', function ($table): void {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
        });
        try {
            app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
            $this->fail('Unknown tenant table must block completeness claim.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PORTABILITY_CATALOG_INCOMPLETE', $exception->errorCode());
        } finally {
            Schema::dropIfExists('unregistered_tenant_data');
        }

        $this->assertDatabaseCount('tenant_portability_operations', 0);
        Queue::assertNothingPushed();
    }

    public function test_byte_limit_never_publishes_a_truncated_archive(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['tenancy.portability.export_bytes' => 300]);
        $operation = app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));

        $this->work();

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'failed', 'bytes' => 300]);
        $this->assertDatabaseCount('failed_jobs', 1);
        Storage::disk('local')->assertMissing($this->path($operation));
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    public function test_transport_failure_keeps_a_visible_operation_for_explicit_redispatch(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['tenancy.schedule_connection' => 'null', 'queue.connections.null.driver' => 'null']);
        try {
            app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
            $this->fail('An export must reject non-durable transport.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('tenant_portability_operations', 1);
        }
        $pending = DB::table('tenant_portability_operations')->sole();
        $this->assertSame('pending', $pending->status);
        config(['tenancy.schedule_connection' => 'database']);

        app(OrganizationPortabilityService::class)->retryExport($organization->id, (string) $pending->id, $this->audit($organization));
        $this->work();

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $pending->id, 'status' => 'ready']);
        $this->assertDatabaseCount('tenant_portability_operations', 1);
    }

    public function test_cleanup_refuses_a_still_live_archive(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $operation = $this->build($organization);
        try {
            app(OrganizationPortabilityService::class)->expire($organization->id, $operation->id);
            $this->fail('A live archive must not be removed by expiry cleanup.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('EXPORT_NOT_EXPIRED', $exception->errorCode());
        }

        Storage::disk('local')->assertExists($this->path($operation));
        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'ready']);
    }

    public function test_unverified_operator_actor_is_refused_without_queue_or_operation(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $actor = User::factory()->withMfa()->unverified()->create();
        Queue::fake([BuildOrganizationExport::class]);

        $this->assertSame(1, Artisan::call('organizations:portability', ['organization' => $organization->id,
            'action' => 'export', '--actor' => $actor->id, '--reason' => 'Invalid actor']));

        $this->assertDatabaseCount('tenant_portability_operations', 0);
        Queue::assertNothingPushed();
    }

    private function build(Organization $organization): PortabilityOperation
    {
        $operation = app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
        $this->work();

        return app(PortabilityRepository::class)->find($organization->id, $operation->id);
    }

    public function test_cli_export_queues_explicit_target_and_validates_input_before_any_write(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        Queue::fake([BuildOrganizationExport::class]);

        $this->assertSame(0, Artisan::call('organizations:portability', ['organization' => $organization->id,
            'action' => 'export', '--actor' => $organization->owner_user_id, '--reason' => 'CLI export']));
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($output);
        Queue::assertPushed(BuildOrganizationExport::class, fn ($job) => $job->organizationId === $organization->id && $job->operationId === $output['id']);
        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $output['id'], 'status' => 'pending', 'reason' => 'CLI export']);

        $this->assertSame(1, Artisan::call('organizations:portability', ['organization' => '../other',
            'action' => 'export', '--actor' => $organization->owner_user_id, '--reason' => 'Bad UUID']));
        $this->assertDatabaseCount('tenant_portability_operations', 1);
    }

    public function test_missing_source_file_prevents_ready_archive_then_retry_recovers_without_scope_leak(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        $artifact = app(TenantArtifacts::class)->store($context, 'source.txt', 'original');
        $source = TenantResourceNamespace::path($organization->id, 'artifacts/'.$artifact->id);
        Storage::disk('local')->delete($source);
        $operation = app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
        try {
            app(OrganizationPortabilityService::class)->generate($organization->id, $operation->id);
            $this->fail('A missing source must never produce a partial ready archive.');
        } catch (ArtifactStorageException) {
            $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'pending']);
            Storage::disk('local')->assertMissing($this->path($operation));
            $this->assertFalse(app(TenantExecutionScope::class)->active());
        }
        Storage::disk('local')->put($source, 'original');

        $this->work();

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'ready']);
        $this->assertFalse(app(TenantExecutionScope::class)->active());
    }

    #[Group('tenant-isolation')]
    public function test_operator_verification_revoked_after_enqueue_fails_without_external_archive(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        $operation = app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
        DB::table('users')->where('id', $organization->owner_user_id)->update(['email_verified_at' => null]);

        $this->work();

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $operation->id, 'status' => 'failed']);
        $this->assertDatabaseCount('failed_jobs', 1);
        Storage::disk('local')->assertMissing($this->path($operation));
    }

    public function test_export_storage_reservation_prevents_unbounded_pending_archives(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        config(['tenancy.portability.export_storage_bytes' => 67108864]);
        Queue::fake([BuildOrganizationExport::class]);
        app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
        try {
            app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
            $this->fail('Pending archive reservations must count toward the export budget.');
        } catch (TenantLimitExceededException) {
            $this->assertDatabaseCount('tenant_portability_operations', 1);
        }

        Queue::assertPushed(BuildOrganizationExport::class, 1);
    }

    public function test_new_unreviewed_column_blocks_export_instead_of_leaking_it(): void
    {
        Storage::fake('local');
        $organization = Organization::factory()->withMfaOwner()->create();
        Queue::fake([BuildOrganizationExport::class]);
        DB::statement('ALTER TABLE organizations ADD COLUMN unreviewed_secret TEXT');
        try {
            app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
            $this->fail('A new column requires an explicit portability review.');
        } catch (DomainConflictException $exception) {
            $this->assertSame('PORTABILITY_CATALOG_INCOMPLETE', $exception->errorCode());
        } finally {
            DB::statement('ALTER TABLE organizations DROP COLUMN unreviewed_secret');
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('tenant_portability_operations', 0);
    }

    #[Group('tenant-isolation')]
    public function test_one_archive_cleanup_failure_does_not_block_another_tenant(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $first = Organization::factory()->withMfaOwner()->create();
        $second = Organization::factory()->withMfaOwner()->create();
        $a = $this->build($first);
        $b = $this->build($second);
        $this->travel(24)->hours();
        $archive = $this->mock(PortabilityArchive::class);
        $archive->shouldReceive('delete')->once()->withArgs(fn ($op) => $op->id === $a->id)->andThrow(new ArtifactStorageException('Storage unavailable'));
        $archive->shouldReceive('delete')->once()->withArgs(fn ($op) => $op->id === $b->id)
            ->andReturnUsing(function () use ($b): void {
                Storage::disk('local')->delete($this->path($b));
            });

        $this->assertSame(1, app(OrganizationPortabilityService::class)->expireBatch());

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $a->id, 'status' => 'ready']);
        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $b->id, 'status' => 'expired', 'bytes' => 0]);
        Storage::disk('local')->assertExists($this->path($a));
        Storage::disk('local')->assertMissing($this->path($b));
    }

    public function test_a_failed_first_cleanup_page_rotates_without_starving_other_tenants(): void
    {
        $this->freezeTime();
        Storage::fake('local');
        $first = Organization::factory()->withMfaOwner()->create();
        $a = $this->build($first);
        $this->travel(1)->seconds();
        $second = Organization::factory()->withMfaOwner()->create();
        $b = $this->build($second);
        $this->travel(24)->hours();
        $archive = $this->mock(PortabilityArchive::class);
        $archive->shouldReceive('delete')->once()->withArgs(fn ($op) => $op->id === $a->id)->andThrow(new ArtifactStorageException('Storage unavailable'));
        $archive->shouldReceive('delete')->once()->withArgs(fn ($op) => $op->id === $b->id)
            ->andReturnUsing(function () use ($b): void {
                Storage::disk('local')->delete($this->path($b));
            });

        $this->assertSame(1, app(OrganizationPortabilityService::class)->expireBatch(1));
        $this->assertSame(0, app(OrganizationPortabilityService::class)->expireBatch(1));

        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $a->id, 'status' => 'ready']);
        $this->assertSame($a->expiresAt, app(PortabilityRepository::class)->find($first->id, $a->id)->expiresAt);
        $this->assertDatabaseHas('tenant_portability_operations', ['id' => $b->id, 'status' => 'expired', 'bytes' => 0]);
        Storage::disk('local')->assertExists($this->path($a));
        Storage::disk('local')->assertMissing($this->path($b));
    }

    public function test_private_s3_adapter_uses_original_source_disks_and_refuses_public_archive_disk(): void
    {
        Storage::fake('local');
        Storage::fake('s3');
        $organization = Organization::factory()->withMfaOwner()->create();
        $context = app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
        app(TenantArtifacts::class)->store($context, 'source.txt', 'local-source');
        config(['filesystems.artifacts' => 's3']);

        $operation = $this->build($organization);
        $stream = app(OrganizationPortabilityService::class)->read($organization->id, $operation->id, $this->audit($organization));
        $contents = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame('s3', $operation->disk);
        $this->assertIsString($contents);
        $this->assertStringContainsString(base64_encode('local-source'), $contents);
        Storage::disk('s3')->assertExists($this->path($operation));
        config(['filesystems.artifacts' => 'public']);
        $this->expectException(\LogicException::class);
        app(OrganizationPortabilityService::class)->requestExport($organization->id, $this->audit($organization));
    }

    private function work(): void
    {
        $this->assertSame(0, Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'reports', '--once' => true, '--sleep' => 0]));
    }

    private function audit(Organization $organization): OrganizationAuditData
    {
        return new OrganizationAuditData($organization->owner_user_id, 'Test operator request', (string) Str::uuid());
    }

    private function path(PortabilityOperation $operation): string
    {
        return TenantResourceNamespace::path($operation->organizationId, 'exports/'.$operation->id.'.ndjson');
    }
}
