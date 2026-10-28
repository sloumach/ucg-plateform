<?php

namespace App\Modules\Performance\Application\Contracts;

/**
 * @phpstan-type StatisticalIssue array{code:string,field:?string,column:?int,severity:string}
 * @phpstan-type StatisticalAnalysis array{columns:int,row_count:int,issues:list<StatisticalIssue>,rows:list<array{line:int,issues:list<StatisticalIssue>,keys:array<string,string>}>}
 */
interface StatisticalSourceAudit
{
    /**
     * Keys contain transient source values for comparison; never serialize them.
     * The cutoff is declared by the caller, not verified against tenant-local time.
     *
     * @return StatisticalAnalysis
     */
    public function analyze(string $contents, string $delimiter, ?string $asOf, int $maximumRows = 100): array;
}
