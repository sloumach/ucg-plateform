<?php

namespace Tests\Feature\Infrastructure;

use App\Contracts\Storage\ArtifactStorage;
use App\Exceptions\ArtifactStorageException;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantResourceNamespaceTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function unsafePaths(): array
    {
        return ['parent traversal' => ['../outside'], 'nested traversal' => ['reports/../outside'],
            'absolute' => ['/outside'], 'windows drive' => ['C:/outside'], 'backslash' => ['reports\\outside'],
            'encoded traversal' => ['%2e%2e/outside'], 'empty segment' => ['reports//outside'],
            'dot segment' => ['reports/./outside'], 'nul' => ["reports/\0outside"], 'control' => ["reports/\noutside"]];
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_paths_are_rejected_before_storage_access(string $path): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(ArtifactStorage::class)->putForTenant('tenant-456', $path, 'private');
    }

    public function test_canonical_uuid_and_distinct_purposes_cannot_alias_resource_names(): void
    {
        $id = '6602D0D2-B528-4472-B1A7-C3B7B7F454AE';

        $this->assertSame(TenantResourceNamespace::key(strtolower($id), 'cache', 'report'), TenantResourceNamespace::key($id, 'cache', 'report'));
        $this->assertNotSame(TenantResourceNamespace::key($id, 'cache', 'report'), TenantResourceNamespace::key($id, 'lock', 'report'));
        $this->assertSame('tenants/6602d0d2-b528-4472-b1a7-c3b7b7f454ae/reports/123.json', TenantResourceNamespace::path($id, 'reports/123.json'));
    }

    public function test_public_artifact_disk_is_refused_without_exposing_the_file(): void
    {
        Storage::fake('public');
        config(['filesystems.artifacts' => 'public']);

        try {
            app(ArtifactStorage::class)->putForTenant('tenant-456', 'private.json', '{}');
            $this->fail('A private tenant artifact must not use the public disk.');
        } catch (ArtifactStorageException) {
            Storage::disk('public')->assertMissing('tenants/tenant-456/private.json');
        }
    }
}
