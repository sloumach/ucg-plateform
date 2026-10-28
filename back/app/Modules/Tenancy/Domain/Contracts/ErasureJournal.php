<?php

namespace App\Modules\Tenancy\Domain\Contracts;

/** @phpstan-import-type ErasureOperation from ManualErasureRepository
 * @phpstan-import-type ErasureManifest from ManualErasureRepository
 *
 * @phpstan-type ErasureIntent array{id: string, organization_id: string, scope: string, target_id: string, source_digest: string, schema_digest: string, reference: string, manifest: ErasureManifest, created_at: string}
 */
interface ErasureJournal
{
    public function initialize(): void;

    /** Validates a separate connection and a key that must be kept outside application backups. */
    public function assertReady(): void;

    /** @param ErasureOperation $operation */
    public function append(array $operation): void;

    /** @return list<ErasureIntent> */
    public function intents(string $organizationId, int $after, int $limit): array;

    public function head(string $organizationId): string;

    public function preserve(string $organizationId, string $requestId, string $reference): void;

    /** @return list<array{request_id: string, reference: string}> */
    public function preservations(string $organizationId): array;
}
