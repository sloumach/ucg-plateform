<?php

namespace App\Modules\Teams\Domain\Contracts;

use App\Modules\Teams\Domain\Models\ExternalOpponent;
use App\Modules\Teams\Domain\Models\ExternalOpponentSnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ExternalOpponentRepository
{
    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ExternalOpponent>
     */
    public function paginate(string $organizationId, array $filters): LengthAwarePaginator;

    public function find(string $organizationId, string $id, bool $lock = false): ExternalOpponent;

    public function count(string $organizationId): int;

    /** @param array<string, mixed> $attributes */
    public function save(?ExternalOpponent $opponent, array $attributes): ExternalOpponent;

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ExternalOpponentSnapshot>
     */
    public function snapshots(string $organizationId, string $opponentId, array $filters): LengthAwarePaginator;

    public function snapshotCount(string $organizationId, string $opponentId): int;

    public function findSnapshot(string $organizationId, string $snapshotId): ExternalOpponentSnapshot;

    /** @param array<string, mixed> $attributes */
    public function capture(array $attributes): ExternalOpponentSnapshot;
}
