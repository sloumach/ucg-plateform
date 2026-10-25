<?php

namespace Tests\Unit;

use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentSecurityTest extends TestCase
{
    /** @return array<string, array{string, bool}> */
    public static function pdfContents(): array
    {
        return ['minimal PDF' => ["%PDF-1.4\n%%EOF\n", true], 'PDF 2' => ["%PDF-2.0\r\n%%EOF\r\n", true],
            'executable' => ['MZ EXECUTABLE', false], 'fake prefix' => ["junk%PDF-1.4\n%%EOF", false],
            'wrong version' => ["%PDF-1.8\n%%EOF", false], 'missing end' => ["%PDF-1.4\nTRUNCATED", false],
            'executable suffix' => ["%PDF-1.4\n%%EOF\n<?php", false],
            'too large' => ["%PDF-1.4\n".str_repeat('x', 5242880)."\n%%EOF", false]];
    }

    #[DataProvider('pdfContents')]
    public function test_only_bounded_pdf_envelopes_pass(string $contents, bool $expected): void
    {
        $this->assertSame($expected, DocumentSecurity::pdf($contents));
    }

    public function test_five_mebibytes_is_accepted_and_one_extra_byte_is_refused(): void
    {
        $header = "%PDF-1.4\n";
        $end = "\n%%EOF\n";
        $body = str_repeat('x', 5_242_880 - strlen($header) - strlen($end));
        $this->assertTrue(DocumentSecurity::pdf($header.$body.$end));
        $this->assertFalse(DocumentSecurity::pdf($header.$body.'x'.$end));
    }

    /** @return array<string, array{string, int, bool, string, bool}> */
    public static function verdicts(): array
    {
        return ['pending' => ['queued', 0, false, DocumentSecurity::POLICY, false],
            'in progress' => ['processing', 0, false, DocumentSecurity::POLICY, false],
            'clean recent' => ['clean', 100, false, DocumentSecurity::POLICY, true],
            'clean boundary expired' => ['clean', 86400, false, DocumentSecurity::POLICY, false],
            'clean future' => ['clean', -10, false, DocumentSecurity::POLICY, false],
            'clean invalidated' => ['clean', 10, true, DocumentSecurity::POLICY, false],
            'clean old policy' => ['clean', 10, false, 'old-policy', false],
            'clean before complete-reply policy' => ['clean', 10, false, 'clamav-pdf-v1', false],
            'malware' => ['rejected', 10, false, DocumentSecurity::POLICY, false],
            'engine outage' => ['failed', 10, false, DocumentSecurity::POLICY, false]];
    }

    #[DataProvider('verdicts')]
    public function test_download_requires_recent_clean_current_policy_without_invalidation(string $status, int $age, bool $invalidated, string $policy, bool $expected): void
    {
        $now = new CarbonImmutable('2026-10-14T12:00:00Z');
        $scan = (new ContractDocumentScan)->forceFill(['status' => $status, 'policy' => $policy,
            'finished_at' => $now->subSeconds($age), 'invalidated_at' => $invalidated ? $now : null]);
        $this->assertSame($expected, DocumentSecurity::downloadable($scan, $now));
    }

    /** @return array<string, array{string}> */
    public static function activeStates(): array
    {
        return ['queued' => ['queued'], 'processing' => ['processing']];
    }

    #[DataProvider('activeStates')]
    public function test_expired_lease_is_displayed_as_stale_to_allow_explicit_retry(string $status): void
    {
        $now = new CarbonImmutable('2026-10-14T12:00:00Z');
        $scan = (new ContractDocumentScan)->forceFill(['status' => $status,
            'requested_at' => $now->subSeconds(299), 'invalidated_at' => null]);
        $this->assertSame($status, DocumentSecurity::state($scan, $now));
        $this->assertSame('stale', DocumentSecurity::state($scan, $now->addSecond()));
        $this->assertFalse(DocumentSecurity::downloadable($scan, $now));
    }

    public function test_missing_scan_is_pending_and_never_grants_access(): void
    {
        $now = new CarbonImmutable('2026-10-14T12:00:00Z');
        $this->assertFalse(DocumentSecurity::downloadable(null, $now));
        $this->assertSame('pending', DocumentSecurity::state(null, $now));
    }
}
