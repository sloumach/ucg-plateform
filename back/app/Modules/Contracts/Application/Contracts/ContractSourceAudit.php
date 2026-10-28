<?php

namespace App\Modules\Contracts\Application\Contracts;

use App\Support\Files\JsonDatasetAuditor;

/** @phpstan-import-type DatasetAnalysis from JsonDatasetAuditor */
interface ContractSourceAudit
{
    /** @return DatasetAnalysis */
    public function analyze(string $contents, string $organization, int $maximumRows = 100): array;
}
