<?php

namespace App\Modules\Teams\Application\Contracts;

use App\Support\Files\JsonDatasetAuditor;

/** @phpstan-import-type DatasetAnalysis from JsonDatasetAuditor */
interface TeamSourceAudit
{
    /** @return DatasetAnalysis */
    public function analyze(string $contents, string $organization, int $maximumRows = 100): array;
}
