<?php

namespace App\Modules\Tenancy\Application\Services;

use App\Exceptions\ArtifactStorageException;
use App\Modules\Tenancy\Application\Contracts\TenantArtifactData;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Modules\Tenancy\Domain\Contracts\ArtifactLedger;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** @phpstan-import-type ArtifactRecord from ArtifactLedger */
final readonly class TenantArtifactService implements TenantArtifacts
{
    public function __construct(
        private ArtifactLedger $ledger, private Factory $filesystems, private Repository $config,
        private TenantTechnicalLimits $limits, private TenantResolver $resolver,
    ) {}

    public function store(TenantContext $context, string $name, string $contents): TenantArtifactData
    {
        $this->authorize($context);
        if ($name === '' || mb_strlen($name) > 255 || basename($name) !== $name
            || preg_match('/[\x00-\x1f\x7f\\\\:%]/', $name) || in_array($name, ['.', '..'], true)) {
            throw new InvalidArgumentException('An artifact requires a safe display filename.');
        }
        $bytes = strlen($contents);
        $this->limits->assertWithin($context, 'artifact_bytes', $bytes);
        $disk = $this->config->get('filesystems.artifacts');
        if (! is_string($disk) || $disk === '' || strlen($disk) > 100) {
            throw new LogicException('The artifact disk must be configured.');
        }
        $this->privateDisk($disk);
        $organizationId = strtolower($context->organizationId);
        $record = $this->ledger->reserve($organizationId, $name, $bytes, $disk, $this->limits->value($context, 'storage_bytes'));
        $path = $this->path($context, $record['id']);
        try {
            $this->ledger->locked($organizationId, $record['id'], function () use ($context, $disk, $path, $contents, $organizationId, $record): void {
                $this->authorize($context);
                if (! $this->privateDisk($disk)->put($path, $contents, ['visibility' => 'private'])) {
                    throw ArtifactStorageException::writeFailed($path);
                }
                $this->ledger->markReady($organizationId, $record['id']);
            });
        } catch (Throwable $exception) {
            // Only this new object may be removed. Uncertain cleanup retains the durable reservation.
            try {
                $this->delete($context, $record['id']);
            } catch (Throwable $cleanupFailure) {
                report($cleanupFailure);
            }
            throw ArtifactStorageException::writeFailed($path, $exception);
        }

        return new TenantArtifactData($record['id'], $name, $bytes);
    }

    public function read(TenantContext $context, string $artifactId): string
    {
        $this->authorize($context);
        $record = $this->record($context, $artifactId);
        if (! $record['ready']) {
            throw new ModelNotFoundException;
        }
        try {
            $contents = $this->privateDisk($record['disk'])->get($this->path($context, $artifactId));
        } catch (Throwable $exception) {
            throw new ArtifactStorageException('Unable to read the tenant artifact.', previous: $exception);
        }
        if (! is_string($contents)) {
            throw new ModelNotFoundException;
        }

        return $contents;
    }

    public function delete(TenantContext $context, string $artifactId): void
    {
        $this->authorize($context);
        $this->record($context, $artifactId);
        $organizationId = strtolower($context->organizationId);
        $this->ledger->locked($organizationId, $artifactId, function (array $record) use ($context, $artifactId, $organizationId): void {
            $this->authorize($context);
            try {
                $disk = $this->privateDisk($record['disk']);
                $path = $this->path($context, $artifactId);
                if ($disk->exists($path) && ! $disk->delete($path)) {
                    throw new ArtifactStorageException('Unable to remove the tenant artifact.');
                }
            } catch (Throwable $exception) {
                throw new ArtifactStorageException('Unable to remove the tenant artifact.', previous: $exception);
            }
            $this->ledger->release($organizationId, $artifactId);
        });
    }

    public function usedBytes(TenantContext $context): int
    {
        $this->authorize($context);

        return $this->ledger->usedBytes(strtolower($context->organizationId));
    }

    private function authorize(TenantContext $context): void
    {
        $this->resolver->resolve($context->organizationId, '', $context->actorUserId, $context->requestId);
    }

    /** @return ArtifactRecord */
    private function record(TenantContext $context, string $artifactId): array
    {
        if (! Str::isUuid($artifactId)) {
            throw new ModelNotFoundException;
        }

        return $this->ledger->find(strtolower($context->organizationId), strtolower($artifactId));
    }

    private function path(TenantContext $context, string $artifactId): string
    {
        return TenantResourceNamespace::path($context->organizationId, 'artifacts/'.strtolower($artifactId));
    }

    private function privateDisk(string $name): Filesystem
    {
        $driver = $this->config->get('filesystems.disks.'.$name.'.driver');
        $visibility = $this->config->get('filesystems.disks.'.$name.'.visibility', 'private');
        if (! in_array($driver, ['local', 's3'], true) || $visibility !== 'private' || $name === 'public') {
            throw new LogicException('Tenant artifacts require a private local or S3 disk.');
        }

        return $this->filesystems->disk($name);
    }
}
