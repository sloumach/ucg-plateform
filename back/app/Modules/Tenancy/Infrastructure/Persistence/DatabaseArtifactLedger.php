<?php

namespace App\Modules\Tenancy\Infrastructure\Persistence;

use App\Modules\Tenancy\Domain\Contracts\ArtifactLedger;
use App\Modules\Tenancy\Domain\Exceptions\TenantLimitExceededException;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use LogicException;

/** @phpstan-import-type ArtifactRecord from ArtifactLedger */
final readonly class DatabaseArtifactLedger implements ArtifactLedger
{
    public function __construct(private DatabaseManager $database) {}

    public function reserve(string $organizationId, string $name, int $bytes, string $disk, int $limit): array
    {
        $connection = $this->database->connection();
        // A durable reservation must commit before external I/O; never join a caller's transaction.
        if ($connection->transactionLevel() !== 0 && ! app()->runningUnitTests()) {
            throw new LogicException('Artifact writes must run outside an enclosing database transaction.');
        }

        return $connection->transaction(function () use ($organizationId, $name, $bytes, $disk, $limit, $connection): array {
            $this->lockOrganization($organizationId);
            $connection->table('tenant_storage_usage')->insertOrIgnore(['organization_id' => $organizationId, 'bytes' => 0]);
            $used = $this->usedBytes($organizationId);
            if ($bytes > $limit - $used) {
                throw new TenantLimitExceededException;
            }
            $id = (string) Str::uuid7();
            $connection->table('tenant_storage_usage')->where('organization_id', $organizationId)->increment('bytes', $bytes);
            $connection->table('tenant_artifacts')->insert([
                'id' => $id, 'organization_id' => $organizationId, 'name' => $name, 'bytes' => $bytes,
                'disk' => $disk, 'ready' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            return ['id' => $id, 'name' => $name, 'bytes' => $bytes, 'disk' => $disk, 'ready' => false];
        });
    }

    public function find(string $organizationId, string $artifactId): array
    {
        $record = $this->database->connection()->table('tenant_artifacts')
            ->where('organization_id', $organizationId)->where('id', $artifactId)->first();
        if ($record === null) {
            throw new ModelNotFoundException;
        }

        return ['id' => (string) $record->id, 'name' => (string) $record->name,
            'bytes' => (int) $record->bytes, 'disk' => (string) $record->disk, 'ready' => (bool) $record->ready];
    }

    public function locked(string $organizationId, string $artifactId, Closure $operation): mixed
    {
        // One lock order for all storage mutations. No automatic retry around filesystem operations.
        return $this->database->connection()->transaction(function () use ($organizationId, $artifactId, $operation): mixed {
            $this->lockOrganization($organizationId);

            return $operation($this->find($organizationId, $artifactId));
        });
    }

    public function markReady(string $organizationId, string $artifactId): void
    {
        $this->database->connection()->table('tenant_artifacts')->where('organization_id', $organizationId)
            ->where('id', $artifactId)->update(['ready' => true, 'updated_at' => now()]);
    }

    public function release(string $organizationId, string $artifactId): void
    {
        $record = $this->find($organizationId, $artifactId);
        $changed = $this->database->connection()->table('tenant_storage_usage')->where('organization_id', $organizationId)
            ->where('bytes', '>=', $record['bytes'])->decrement('bytes', $record['bytes']);
        if ($changed !== 1) {
            throw new LogicException('Artifact usage accounting is inconsistent.');
        }
        $this->database->connection()->table('tenant_artifacts')->where('organization_id', $organizationId)->where('id', $artifactId)->delete();
    }

    public function usedBytes(string $organizationId): int
    {
        return (int) $this->database->connection()->table('tenant_storage_usage')->where('organization_id', $organizationId)->value('bytes');
    }

    private function lockOrganization(string $organizationId): void
    {
        if ($this->database->connection()->table('organizations')->where('id', $organizationId)->lockForUpdate()->first(['id']) === null) {
            throw new ModelNotFoundException;
        }
    }
}
