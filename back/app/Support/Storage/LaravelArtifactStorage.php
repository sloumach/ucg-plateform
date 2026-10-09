<?php

namespace App\Support\Storage;

use App\Contracts\Storage\ArtifactStorage;
use App\Exceptions\ArtifactStorageException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use InvalidArgumentException;
use LogicException;
use Throwable;

final readonly class LaravelArtifactStorage implements ArtifactStorage
{
    public function __construct(private FilesystemFactory $filesystems) {}

    public function putForTenant(string $tenantId, string $relativePath, string $contents): string
    {
        $path = sprintf(
            'tenants/%s/%s',
            $this->normalizeTenantId($tenantId),
            $this->normalizeRelativePath($relativePath),
        );

        try {
            $written = $this->disk()->put($path, $contents, ['visibility' => 'private']);
        } catch (Throwable $exception) {
            throw ArtifactStorageException::writeFailed($path, $exception);
        }

        if (! $written) {
            throw ArtifactStorageException::writeFailed($path);
        }

        return $path;
    }

    private function disk(): Filesystem
    {
        $disk = config('filesystems.artifacts');

        if (! is_string($disk) || trim($disk) === '') {
            throw new LogicException('The artifact storage disk must be configured.');
        }

        return $this->filesystems->disk($disk);
    }

    private function normalizeTenantId(string $tenantId): string
    {
        $tenantId = trim($tenantId);

        if (
            $tenantId === ''
            || $tenantId === '.'
            || $tenantId === '..'
            || str_contains($tenantId, '/')
            || str_contains($tenantId, '\\')
            || str_contains($tenantId, "\0")
        ) {
            throw new InvalidArgumentException('The tenant identifier is not storage-safe.');
        }

        return $tenantId;
    }

    private function normalizeRelativePath(string $relativePath): string
    {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $segments = explode('/', $relativePath);

        if (
            $relativePath === ''
            || str_contains($relativePath, "\0")
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)
        ) {
            throw new InvalidArgumentException('The artifact path is not storage-safe.');
        }

        return implode('/', $segments);
    }
}
