<?php

namespace App\Modules\Tenancy\Infrastructure\Resources;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Application\Services\RetentionGuard;
use App\Modules\Tenancy\Domain\Contracts\PortabilityArchive;
use App\Modules\Tenancy\Domain\Data\PortabilityArchiveData;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use LogicException;

final readonly class PrivatePortabilityArchive implements PortabilityArchive
{
    private const int BLOCK_BYTES = 1048576;

    public function __construct(private Factory $filesystems, private Repository $config, private RetentionGuard $retention) {}

    public function validateDisk(string $disk): void
    {
        $this->disk($disk);
    }

    public function build(PortabilityOperation $operation, iterable $rows, int $byteLimit): PortabilityArchiveData
    {
        $target = tmpfile();
        if (! is_resource($target)) {
            throw new ArtifactStorageException('Unable to allocate a private archive buffer.');
        }
        $started = hrtime(true);
        $count = 0;
        $files = 0;
        try {
            $this->line($target, ['type' => 'manifest', 'format' => 'ucg-tenant-ndjson', 'version' => 1,
                'organization_id' => $operation->organizationId, 'operation_id' => $operation->id,
                'request_id' => $operation->requestId, 'generated_at' => now()->toIso8601String(),
                'global_accounts' => 'excluded; reconcile references separately'], $byteLimit);
            foreach ($rows as $row) {
                $this->deadline($started);
                $this->line($target, ['type' => 'row', ...$row], $byteLimit);
                $count++;
                if ($row['table'] === 'tenant_artifacts') {
                    $data = $row['data'];
                    if (! is_string($data['id'] ?? null) || ! Str::isUuid($data['id'])
                        || ! is_string($data['disk'] ?? null) || ! isset($data['bytes'])
                        || ! ($data['ready'] ?? false) || ($data['organization_id'] ?? null) !== $operation->organizationId) {
                        throw new DomainConflictException(__('tenancy.portability.artifact_pending'), 'EXPORT_ARTIFACT_NOT_READY');
                    }
                    $this->file($target, $operation->organizationId, $data['id'], $data['disk'], (int) $data['bytes'], $byteLimit, $started);
                    $files++;
                }
            }
            $this->line($target, ['type' => 'end', 'rows' => $count, 'files' => $files], $byteLimit);
            $size = ftell($target);
            if ($size === false || ! rewind($target)) {
                throw new ArtifactStorageException('Unable to finalize archive buffer.');
            }
            $hash = hash_init('sha256');
            hash_update_stream($hash, $target);
            $digest = hash_final($hash);
            rewind($target);
            if (! $this->disk((string) $operation->disk)->put($this->path($operation), $target, ['visibility' => 'private'])) {
                throw new ArtifactStorageException('Unable to persist private archive.');
            }

            return new PortabilityArchiveData($size, $digest, $count, $files);
        } finally {
            fclose($target);
        }
    }

    public function read(PortabilityOperation $operation)
    {
        $source = $this->disk((string) $operation->disk)->readStream($this->path($operation));
        if (! is_resource($source)) {
            throw new ArtifactStorageException('Unable to read private archive.');
        }
        $target = tmpfile();
        if (! is_resource($target)) {
            fclose($source);
            throw new ArtifactStorageException('Unable to allocate archive verification buffer.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        $started = hrtime(true);
        try {
            while (! feof($source)) {
                $this->deadline($started);
                $chunk = fread($source, self::BLOCK_BYTES);
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new ArtifactStorageException('Archive stream interrupted.');
                }
                $bytes += strlen($chunk);
                if ($bytes > $operation->bytes || fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new ArtifactStorageException('Archive size or buffer is invalid.');
                }
                hash_update($hash, $chunk);
            }
            if ($bytes !== $operation->bytes || $operation->sha256 === null
                || ! hash_equals($operation->sha256, hash_final($hash))) {
                throw new ArtifactStorageException('Private archive integrity check failed.');
            }
            rewind($target);

            return $target;
        } catch (\Throwable $exception) {
            fclose($target);
            throw $exception;
        } finally {
            fclose($source);
        }
    }

    public function delete(PortabilityOperation $operation): void
    {
        $this->retention->destructive($operation->organizationId, fn () => $this->remove((string) $operation->disk, $this->path($operation)));
    }

    public function deleteArtifact(string $organizationId, array $artifact): void
    {
        if (! Str::isUuid($artifact['id'])) {
            throw new LogicException('Invalid artifact identity.');
        }
        $this->retention->destructive($organizationId,
            fn () => $this->remove($artifact['disk'], TenantResourceNamespace::path($organizationId, 'artifacts/'.strtolower($artifact['id']))));
    }

    /**
     * @param  resource  $target
     * @param  array<string, mixed>  $record
     */
    private function line($target, array $record, int $limit): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)."\n";
        $position = ftell($target);
        if ($position === false || strlen($line) > $limit - $position) {
            throw new TenantLimitExceededException;
        }
        if (fwrite($target, $line) !== strlen($line)) {
            throw new ArtifactStorageException('Unable to write complete archive record.');
        }
    }

    /** @param resource $target */
    private function file($target, string $organizationId, string $id, string $disk, int $expectedBytes, int $limit, int $started): void
    {
        if ($expectedBytes < 0 || $expectedBytes > $limit) {
            throw new TenantLimitExceededException;
        }
        $source = $this->disk($disk)->readStream(TenantResourceNamespace::path($organizationId, 'artifacts/'.strtolower($id)));
        if (! is_resource($source)) {
            throw new ArtifactStorageException('A tenant artifact is missing or unreadable.');
        }
        $bytes = 0;
        $hash = hash_init('sha256');
        try {
            while (! feof($source)) {
                $this->deadline($started);
                $chunk = fread($source, self::BLOCK_BYTES);
                if ($chunk === false || ($chunk === '' && ! feof($source))) {
                    throw new ArtifactStorageException('Tenant artifact stream interrupted.');
                }
                $offset = $bytes;
                $bytes += strlen($chunk);
                if ($bytes > $expectedBytes) {
                    throw new ArtifactStorageException('Tenant artifact size changed.');
                }
                hash_update($hash, $chunk);
                if ($chunk !== '') {
                    $this->line($target, ['type' => 'file_chunk', 'artifact_id' => $id, 'offset' => $offset, 'base64' => base64_encode($chunk)], $limit);
                }
            }
            if ($bytes !== $expectedBytes) {
                throw new ArtifactStorageException('Tenant artifact size mismatch.');
            }
            $this->line($target, ['type' => 'file_end', 'artifact_id' => $id, 'bytes' => $bytes, 'sha256' => hash_final($hash)], $limit);
        } finally {
            fclose($source);
        }
    }

    private function deadline(int $started): void
    {
        if (hrtime(true) - $started > 45_000_000_000) {
            throw new DomainConflictException(__('tenancy.portability.too_long'), 'EXPORT_DURATION_LIMIT');
        }
    }

    private function path(PortabilityOperation $operation): string
    {
        if (! Str::isUuid($operation->id) || ! Str::isUuid($operation->organizationId) || $operation->kind !== 'export') {
            throw new LogicException('An archive requires a tenant export identity.');
        }

        return TenantResourceNamespace::path($operation->organizationId, 'exports/'.$operation->id.'.ndjson');
    }

    private function disk(string $name): Filesystem
    {
        $driver = $this->config->get('filesystems.disks.'.$name.'.driver');
        if (! in_array($driver, ['local', 's3'], true) || $name === 'public'
            || $this->config->get('filesystems.disks.'.$name.'.visibility', 'private') !== 'private') {
            throw new LogicException('Portability requires a private local or S3 disk.');
        }

        return $this->filesystems->disk($name);
    }

    private function remove(string $disk, string $path): void
    {
        $store = $this->disk($disk);
        if ($store->exists($path) && ! $store->delete($path)) {
            throw new ArtifactStorageException('Unable to delete the exact private archive or artifact.');
        }
    }
}
