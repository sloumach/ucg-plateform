<?php

namespace App\Support\Storage;

use App\Contracts\Storage\ArtifactStorage;
use App\Exceptions\ArtifactStorageException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use LogicException;
use Throwable;

/** Low-level transport for explicit infrastructure contexts; business modules use TenantArtifacts. */
final readonly class LaravelArtifactStorage implements ArtifactStorage
{
    public function __construct(private FilesystemFactory $filesystems) {}

    public function putForTenant(string $tenantId, string $relativePath, string $contents): string
    {
        $path = TenantResourceNamespace::path($tenantId, $relativePath);
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
        if (! is_string($disk) || trim($disk) === ''
            || (! in_array(config('filesystems.disks.'.$disk.'.driver'), ['local', 's3'], true)
                && ! (app()->runningUnitTests() && config('filesystems.disks.'.$disk.'.driver') === null))
            || config('filesystems.disks.'.$disk.'.visibility', 'private') !== 'private' || $disk === 'public') {
            throw new LogicException('The artifact storage disk must be private and configured.');
        }

        return $this->filesystems->disk($disk);
    }
}
