<?php

namespace App\Modules\Competition\Application\Contracts;

use App\Support\Files\JsonDatasetAuditor;

/** @phpstan-import-type DatasetAnalysis from JsonDatasetAuditor */
interface MatchSourceAudit
{
    /** @return DatasetAnalysis */
    public function analyze(string $contents, string $organization, int $maximumRows = 100): array;
}
