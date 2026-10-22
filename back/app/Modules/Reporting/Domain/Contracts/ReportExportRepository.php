<?php

namespace App\Modules\Reporting\Domain\Contracts;

use App\Modules\Reporting\Domain\Models\ReportExport;
use Illuminate\Pagination\LengthAwarePaginator;

interface ReportExportRepository
{
    public function find(string $org, int $actor, string $id, bool $lock = false): ReportExport;

    public function byKey(string $org, int $actor, string $key): ?ReportExport;

    public function activeCount(string $org): int;

    /** @param array<string,mixed> $attributes */
    public function save(?ReportExport $row, array $attributes): ReportExport;

    /** @param list<array{card:string,view:string}> $allowed
     * @return LengthAwarePaginator<int,ReportExport> */
    public function paginate(string $org, int $actor, int $page, array $allowed): LengthAwarePaginator;
}
