<?php

namespace App\Modules\Tenancy\Domain\Contracts;

use App\Modules\Tenancy\Domain\Data\PortabilityOperation;

interface PortabilityRepository
{
    public function assertCatalog(): void;

    /** @return iterable<array{table: string, data: array<string, mixed>}> */
    public function rows(string $organizationId, int $limit): iterable;

    /** @return array<string, int> */
    public function counts(string $organizationId): array;

    /** @param array<string, mixed> $attributes */
    public function create(string $organizationId, array $attributes): PortabilityOperation;

    public function find(string $organizationId, string $operationId): PortabilityOperation;

    /** @param array<string, mixed> $attributes */
    public function update(string $organizationId, string $operationId, array $attributes): void;

    public function reservedExportBytes(string $organizationId): int;

    /** @return list<PortabilityOperation> */
    public function exports(string $organizationId, int $limit): array;

    /** @return list<PortabilityOperation> */
    public function expiredExports(int $limit): array;

    /** @return list<array{id: string, disk: string, bytes: int}> */
    public function artifacts(string $organizationId, int $limit): array;

    public function removeSettings(string $organizationId): void;

    public function hasPurge(string $organizationId): bool;
}
