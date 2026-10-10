<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use Closure;

/** @phpstan-type ArtifactRecord array{id: string, name: string, bytes: int, disk: string, ready: bool} */
interface ArtifactLedger
{
    /** @return ArtifactRecord */
    public function reserve(string $organizationId, string $name, int $bytes, string $disk, int $limit): array;

    /** @return ArtifactRecord */
    public function find(string $organizationId, string $artifactId): array;

    /**
     * @template T
     *
     * @param  Closure(ArtifactRecord): T  $operation
     * @return T
     */
    public function locked(string $organizationId, string $artifactId, Closure $operation): mixed;

    public function markReady(string $organizationId, string $artifactId): void;

    public function release(string $organizationId, string $artifactId): void;

    public function usedBytes(string $organizationId): int;
}
