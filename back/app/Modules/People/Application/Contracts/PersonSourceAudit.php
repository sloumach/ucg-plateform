<?php

namespace App\Modules\People\Application\Contracts;

/**
 * @phpstan-type SourceIssue array{code: string, field: ?string, column: ?int, severity: string}
 * @phpstan-type SourceAnalysis array{columns: int, row_count: int, issues: list<SourceIssue>, rows: list<array{line: int, issues: list<SourceIssue>, keys: array<string, string>}>}
 */
interface PersonSourceAudit
{
    /**
     * Keys are transient personal values for duplicate comparisons; never serialize them in reports.
     *
     * @param  array<string, int>  $mapping
     * @param  array<string, string>  $defaults
     * @param  list<array{column: int, reason_reference: string}>  $ignored
     * @return SourceAnalysis
     */
    public function analyze(string $contents, string $delimiter, array $mapping, array $defaults, array $ignored, int $maximumRows = 1000): array;
}
