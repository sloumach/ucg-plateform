<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Data\PortabilityArchiveData;
use App\Modules\Tenancy\Domain\Data\PortabilityOperation;

interface PortabilityArchive
{
    public function validateDisk(string $disk): void;

    /** @param iterable<array{table: string, data: array<string, mixed>}> $rows */
    public function build(PortabilityOperation $operation, iterable $rows, int $byteLimit): PortabilityArchiveData;

    /** @return resource */
    public function read(PortabilityOperation $operation);

    public function delete(PortabilityOperation $operation): void;

    /** @param array{id: string, disk: string, bytes: int} $artifact */
    public function deleteArtifact(string $organizationId, array $artifact): void;
}
