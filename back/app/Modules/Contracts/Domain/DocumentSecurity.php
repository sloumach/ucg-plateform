<?php

namespace App\Modules\Contracts\Domain;

use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use Carbon\CarbonImmutable;

final class DocumentSecurity
{
    public const string POLICY = 'clamav-pdf-v1';

    public const int MAX_ATTEMPTS = 1000;

    public const int LEASE_SECONDS = 300;

    public const int CLEAN_AGE_SECONDS = 86400;

    public const int LINK_SECONDS = 300;

    public const array STATUSES = ['queued', 'processing', 'clean', 'rejected', 'failed'];

    public const array REASONS = ['malware', 'invalid_pdf', 'integrity', 'scanner_unavailable', 'scanner_response',
        'scanner_database_stale', 'dispatch_failed', 'worker_failed', 'timed_out'];

    public static function downloadable(?ContractDocumentScan $scan, CarbonImmutable $now): bool
    {
        return $scan !== null && $scan->status === 'clean' && $scan->invalidated_at === null && $scan->policy === self::POLICY
            && $scan->finished_at !== null && $scan->finished_at->lte($now) && $scan->finished_at->addSeconds(self::CLEAN_AGE_SECONDS)->gt($now);
    }

    public static function state(?ContractDocumentScan $scan, CarbonImmutable $now): string
    {
        if ($scan === null) {
            return 'pending';
        }
        if ($scan->invalidated_at !== null) {
            return 'rejected';
        }

        if (in_array($scan->status, ['queued', 'processing'], true)
            && $scan->requested_at->addSeconds(self::LEASE_SECONDS)->lte($now)) {
            return 'stale';
        }

        return $scan->status === 'clean' && ! self::downloadable($scan, $now) ? 'stale' : $scan->status;
    }

    public static function pdf(string $contents): bool
    {
        return strlen($contents) >= 12 && strlen($contents) <= 5242880
            && preg_match('/\A%PDF-(?:1\.[0-7]|2\.0)[\r\n]/D', $contents) === 1
            && preg_match('/%%EOF[\x09\x0a\x0c\x0d\x20]*\z/D', $contents) === 1;
    }
}
