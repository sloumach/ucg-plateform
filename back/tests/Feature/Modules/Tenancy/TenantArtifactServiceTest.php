<?php

namespace Tests\Feature\Modules\Tenancy;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Application\Services\TenantResolver;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Modules\Tenancy\Domain\Models\Organization;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantArtifactServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_two_organizations_store_the_same_filename_without_sharing_contents_or_usage(): void
    {
        Storage::fake('local');
        $first = $this->context();
        $second = $this->context();
        $files = app(TenantArtifacts::class);

        $firstFile = $files->store($first, 'report.json', 'first');
        $secondFile = $files->store($second, 'report.json', 'second');

        $this->assertSame('first', $files->read($first, $firstFile->id));
        $this->assertSame('second', $files->read($second, $secondFile->id));
        $this->assertSame(5, $files->usedBytes($first));
        $this->assertSame(6, $files->usedBytes($second));
        Storage::disk('local')->assertExists(['tenants/'.$first->organizationId.'/artifacts/'.$firstFile->id, 'tenants/'.$second->organizationId.'/artifacts/'.$secondFile->id]);
        $this->assertDatabaseCount('tenant_artifacts', 2);
    }

    public function test_rejects_cross_tenant_reads_without_revealing_the_file(): void
    {
        Storage::fake('local');
        $first = $this->context();
        $second = $this->context();
        $files = app(TenantArtifacts::class);
        $artifact = $files->store($first, 'private.txt', 'private');

        $this->expectException(ModelNotFoundException::class);
        $files->read($second, $artifact->id);
    }

    public function test_cross_tenant_deletion_preserves_the_original_file_and_usage(): void
    {
        Storage::fake('local');
        $first = $this->context();
        $second = $this->context();
        $files = app(TenantArtifacts::class);
        $artifact = $files->store($first, 'private.txt', 'private');

        try {
            $files->delete($second, $artifact->id);
            $this->fail('A tenant must not delete another tenant artifact.');
        } catch (ModelNotFoundException) {
            $this->assertSame('private', $files->read($first, $artifact->id));
            $this->assertSame(7, $files->usedBytes($first));
            $this->assertDatabaseHas('tenant_artifacts', ['organization_id' => $first->organizationId, 'id' => $artifact->id, 'ready' => true]);
        }
    }

    public function test_storage_quota_refuses_only_the_exhausted_tenant_and_deletion_releases_usage(): void
    {
        Storage::fake('local');
        $first = $this->context();
        $second = $this->context();
        config(['tenancy.limit_overrides.'.$first->organizationId => ['storage_bytes' => 5]]);
        $files = app(TenantArtifacts::class);
        $artifact = $files->store($first, 'report.txt', '12345');

        try {
            $files->store($first, 'other.txt', '1');
            $this->fail('The cumulative storage quota must reject excess usage.');
        } catch (TenantLimitExceededException) {
            $this->assertSame('12345', $files->read($first, $artifact->id));
            $this->assertSame(5, $files->usedBytes($first));
            $this->assertDatabaseCount('tenant_artifacts', 1);
        }

        $other = $files->store($second, 'report.txt', '123456');
        $this->assertSame(6, $files->usedBytes($second));
        $this->assertSame('123456', $files->read($second, $other->id));
        $files->delete($first, $artifact->id);
        $this->assertSame(0, $files->usedBytes($first));
        Storage::disk('local')->assertMissing('tenants/'.$first->organizationId.'/artifacts/'.$artifact->id);
        $this->assertDatabaseMissing('tenant_artifacts', ['id' => $artifact->id]);
    }

    public function test_file_size_limit_is_measured_in_bytes_before_any_write(): void
    {
        Storage::fake('local');
        $context = $this->context();
        config(['tenancy.limit_overrides.'.$context->organizationId => ['artifact_bytes' => 1]]);

        try {
            app(TenantArtifacts::class)->store($context, 'unicode.txt', 'é');
            $this->fail('A two-byte character must exceed a one-byte limit.');
        } catch (TenantLimitExceededException) {
            $this->assertDatabaseCount('tenant_artifacts', 0);
            $this->assertDatabaseCount('tenant_storage_usage', 0);
            Storage::disk('local')->assertDirectoryEmpty('tenants');
        }
    }

    public function test_stale_context_cannot_read_a_file_after_organization_suspension(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $files = app(TenantArtifacts::class);
        $artifact = $files->store($context, 'report.txt', 'private');
        Organization::query()->whereKey($context->organizationId)->update(['status' => 'suspended']);

        $this->expectException(DomainConflictException::class);
        $files->read($context, $artifact->id);
    }

    public function test_failed_write_releases_only_the_new_reservation_after_confirmed_cleanup(): void
    {
        $context = $this->context();
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        $this->mock(FilesystemFactory::class)->shouldReceive('disk')->with('local')->andReturn($disk);
        $files = app(TenantArtifacts::class);

        try {
            $files->store($context, 'failed.txt', 'private');
            $this->fail('A failed write must report its failure.');
        } catch (ArtifactStorageException) {
            $this->assertSame(0, $files->usedBytes($context));
            $this->assertDatabaseCount('tenant_artifacts', 0);
        }
    }

    public function test_uncertain_cleanup_retains_usage_and_an_unreadable_pending_record(): void
    {
        $context = $this->context();
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $this->mock(FilesystemFactory::class)->shouldReceive('disk')->with('local')->andReturn($disk);
        $files = app(TenantArtifacts::class);

        try {
            $files->store($context, 'failed.txt', 'private');
            $this->fail('An uncertain write must not be published.');
        } catch (ArtifactStorageException) {
            $this->assertSame(7, $files->usedBytes($context));
            $this->assertDatabaseHas('tenant_artifacts', ['organization_id' => $context->organizationId, 'bytes' => 7, 'ready' => false]);
        }
        $id = DB::table('tenant_artifacts')->where('organization_id', $context->organizationId)->value('id');
        $this->assertIsString($id);
        $this->expectException(ModelNotFoundException::class);
        $files->read($context, $id);
    }

    public function test_failed_delete_retains_the_file_metadata_and_quota(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $artifact = app(TenantArtifacts::class)->store($context, 'kept.txt', 'private');
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->once()->andReturn(true);
        $disk->shouldReceive('delete')->once()->andReturn(false);
        $this->mock(FilesystemFactory::class)->shouldReceive('disk')->with('local')->andReturn($disk);
        $files = app(TenantArtifacts::class);

        try {
            $files->delete($context, $artifact->id);
            $this->fail('Quota must not be released before confirmed deletion.');
        } catch (ArtifactStorageException) {
            $this->assertSame(7, $files->usedBytes($context));
            $this->assertDatabaseHas('tenant_artifacts', ['id' => $artifact->id, 'ready' => true]);
            Storage::disk('local')->assertExists('tenants/'.$context->organizationId.'/artifacts/'.$artifact->id);
        }
    }

    public function test_a_new_artifact_with_the_same_name_does_not_overwrite_the_previous_one(): void
    {
        Storage::fake('local');
        $context = $this->context();
        $files = app(TenantArtifacts::class);
        $first = $files->store($context, 'report.txt', 'first');

        $second = $files->store($context, 'report.txt', 'second');

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('first', $files->read($context, $first->id));
        $this->assertSame('second', $files->read($context, $second->id));
        $this->assertSame(11, $files->usedBytes($context));
    }

    public function test_production_rejects_a_nested_transaction_before_external_storage_io(): void
    {
        Storage::fake('local');
        $context = $this->context();
        app()->detectEnvironment(fn () => 'production');
        try {
            app(TenantArtifacts::class)->store($context, 'unsafe.txt', 'private');
            $this->fail('Reservations must commit before external I/O.');
        } catch (\LogicException $exception) {
            $this->assertSame('Artifact writes must run outside an enclosing database transaction.', $exception->getMessage());
            $this->assertDatabaseCount('tenant_artifacts', 0);
            Storage::disk('local')->assertDirectoryEmpty('tenants');
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_s3_adapter_receives_only_a_tenant_prefixed_private_object(): void
    {
        $context = $this->context();
        config(['filesystems.artifacts' => 's3']);
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('put')->once()
            ->withArgs(fn (string $path, string $contents, array $options): bool => str_starts_with($path, 'tenants/'.$context->organizationId.'/artifacts/')
                && $contents === 'private' && $options === ['visibility' => 'private'])
            ->andReturn(true);
        $this->mock(FilesystemFactory::class)->shouldReceive('disk')->with('s3')->andReturn($disk);

        $artifact = app(TenantArtifacts::class)->store($context, 'report.txt', 'private');

        $this->assertDatabaseHas('tenant_artifacts', ['organization_id' => $context->organizationId, 'id' => $artifact->id, 'disk' => 's3', 'ready' => true]);
    }

    /** @return array<string, array{string}> */
    public static function unsafeNames(): array
    {
        return ['traversal' => ['../report.txt'], 'backslash' => ['folder\\report.txt'], 'encoded' => ['%2e%2e.txt'],
            'dot' => ['.'], 'empty' => [''], 'control' => ["report\n.txt"], 'too long' => [str_repeat('a', 256)]];
    }

    #[DataProvider('unsafeNames')]
    public function test_unsafe_display_names_are_rejected_without_creating_resource_records(string $name): void
    {
        $context = $this->context();
        try {
            app(TenantArtifacts::class)->store($context, $name, 'private');
            $this->fail('An unsafe name must be rejected before reservation.');
        } catch (\InvalidArgumentException) {
            $this->assertDatabaseCount('tenant_artifacts', 0);
            $this->assertDatabaseCount('tenant_storage_usage', 0);
        }
    }

    private function context(): TenantContext
    {
        $organization = Organization::factory()->create();

        return app(TenantResolver::class)->resolve($organization->id, '', $organization->owner_user_id, (string) Str::uuid());
    }
}
