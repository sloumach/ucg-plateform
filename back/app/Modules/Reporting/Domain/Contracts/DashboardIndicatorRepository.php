<?php

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\Models\DashboardIndicator;
use App\Modules\Reporting\Domain\Models\DashboardIndicatorRevision;
use Illuminate\Pagination\LengthAwarePaginator;

interface DashboardIndicatorRepository
{
    public function generation(string $org, string $card, bool $lock = false): int;

    public function find(string $org, int $actor, string $id, bool $lock = false): DashboardIndicator;

    public function fingerprint(string $org, int $actor, string $fingerprint): ?DashboardIndicator;

    public function count(string $org, int $actor): int;

    /** @param array<string,mixed> $attributes */
    public function save(?DashboardIndicator $record, array $attributes): DashboardIndicator;

    /** @param array<string,mixed> $attributes */
    public function append(array $attributes): void;

    /** @return LengthAwarePaginator<int,DashboardIndicatorRevision> */
    public function history(string $org, string $id, int $page): LengthAwarePaginator;
}
