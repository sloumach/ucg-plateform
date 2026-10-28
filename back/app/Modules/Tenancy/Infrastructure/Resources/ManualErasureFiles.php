<?php

namespace App\Modules\Tenancy\Infrastructure\Resources;

use App\Exceptions\ArtifactStorageException;
use App\Exceptions\DomainConflictException;
use App\Modules\Tenancy\Domain\Contracts\RetentionControlRepository;
use App\Support\Storage\TenantResourceNamespace;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

final readonly class ManualErasureFiles
{
    public function __construct(private Factory $filesystems, private Repository $config,
        private RetentionControlRepository $controls, private ConnectionInterface $database) {}

    /** @param array{id: string, disk: string, kind: string} $file */
    public function erase(string $organizationId, string $operationId, array $file): void
    {
        if ($this->database->transactionLevel() < 1) {
            throw new LogicException('Erasure I/O requires the organization lock transaction.');
        }
        $state = $this->controls->state($organizationId);
        if ($state['holds'] !== [] || $state['quarantine'] !== 'erasure:'.$operationId
            || ! $this->database->table('tenant_erasure_operations')->where('organization_id', $organizationId)->where('id', $operationId)->where('status', 'erasing')->exists()) {
            throw new DomainConflictException(__('retention.preservation_active'), 'RETENTION_PRESERVATION_ACTIVE');
        }
        $path = $this->path($organizationId, $file);
        $disk = $this->filesystems->disk($file['disk']);
        if ($disk->exists($path) && ! $disk->delete($path)) {
            throw new ArtifactStorageException('Unable to remove the exact erasure object.');
        }
    }

    /** @param array{id: string, disk: string, kind: string} $file */
    public function exists(string $organizationId, array $file): bool
    {
        return $this->filesystems->disk($file['disk'])->exists($this->path($organizationId, $file));
    }

    /** @param array{id: string, disk: string, kind: string} $file */
    private function path(string $organizationId, array $file): string
    {
        if (! Str::isUuid($file['id']) || ! in_array($file['kind'], ['artifact', 'export'], true)
            || ! in_array($this->config->get('filesystems.disks.'.$file['disk'].'.driver'), ['local', 's3'], true)
            || $file['disk'] === 'public' || $this->config->get('filesystems.disks.'.$file['disk'].'.visibility', 'private') !== 'private') {
            throw new LogicException('Erasure requires an exact tenant-owned object on a private disk.');
        }

        return TenantResourceNamespace::path($organizationId, $file['kind'] === 'artifact'
            ? 'artifacts/'.$file['id'] : 'exports/'.$file['id'].'.ndjson');
    }
}
