<?php

namespace App\Modules\Tenancy\Domain\Contracts;

/**
 * @phpstan-type ErasureManifest array{files: list<array{id: string, disk: string, kind: string}>, imports: list<string>, order: list<string>, detach: array<string, list<string>>}
 * @phpstan-type ErasurePlan array{organization_id: string, scope: string, target_id: string, source_digest: string, schema_digest: string, counts: array<string, int>, blockers: list<string>, manifest: ErasureManifest}
 * @phpstan-type ErasureOperation array{id: string, organization_id: string, actor_user_id: int, request_id: string, scope: string, target_id: string, reference: string, source_digest: string, schema_digest: string, status: string, manifest: ErasureManifest, source_intent_id: ?string, restore_epoch: ?string, progress: array{deleted: int, files_cursor: int, detached: bool}, copy_receipts: array<string, string>, version: int, created_at: string}
 */
interface ManualErasureRepository
{
    /** @return ErasurePlan */
    public function plan(string $organizationId, string $scope, string $targetId): array;

    /** @param ErasurePlan $plan
     * @return ErasureOperation
     */
    public function create(array $plan, int $actorId, string $requestId, string $reference, ?string $sourceIntentId = null, ?string $restoreEpoch = null): array;

    /** @return ErasureOperation */
    public function find(string $organizationId, string $operationId): array;

    /** @return ErasureOperation|null */
    public function replay(string $organizationId, string $intentId, string $restoreEpoch): ?array;

    /** @return ErasureOperation|null */
    public function completedIntent(string $organizationId, string $intentId): ?array;

    /** @param ErasureOperation $operation
     * @return ErasureOperation
     */
    public function batch(array $operation, int $actorId, string $requestId): array;

    /** @param ErasureOperation $operation
     * @return ErasureOperation
     */
    public function acknowledge(array $operation, string $category, string $reference, int $actorId, string $requestId): array;

    /** @return array<string, bool> */
    public function guardHealth(): array;

    /** @param ErasureOperation $operation */
    public function assertExecutable(array $operation): void;

    /** @param ErasureOperation $operation */
    public function assertEmpty(array $operation): void;
}
