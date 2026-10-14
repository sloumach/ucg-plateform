<?php

namespace Tests\Feature;

use App\Modules\Contracts\Infrastructure\Scanning\ClamAvDocumentScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ClamAvDocumentScannerTest extends TestCase
{
    public function test_disabled_engine_and_invalid_pdf_never_grant_access(): void
    {
        config(['contract_documents.clamav.enabled' => false]);
        $scanner = app(ClamAvDocumentScanner::class);
        $this->assertSame('scanner_unavailable', $scanner->scan("%PDF-1.4\n%%EOF\n")->reason);
        $this->assertSame('invalid_pdf', $scanner->scan('MZ executable')->reason);
    }

    /** @return array<string, array{string, string, ?string}> */
    public static function replies(): array
    {
        return ['clean' => ['clean', 'clean', null], 'malware' => ['malware', 'rejected', 'malware'],
            'engine error' => ['error', 'failed', 'scanner_response'], 'old database' => ['stale', 'failed', 'scanner_database_stale'],
            'wrong daemon' => ['bad-version', 'failed', 'scanner_response'], 'ambiguous' => ['ambiguous', 'failed', 'scanner_unavailable'],
            'oversized reply' => ['overlong', 'failed', 'scanner_unavailable'], 'truncated' => ['truncated', 'failed', 'scanner_unavailable'],
            'timeout' => ['timeout', 'failed', 'scanner_unavailable']];
    }

    #[DataProvider('replies')]
    public function test_tcp_protocol_streams_bounded_chunks_and_accepts_only_explicit_clean(string $mode, string $status, ?string $reason): void
    {
        $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/fake-clamd.php'), $mode], base_path(), timeout: 8);
        $peer->start();
        try {
            $this->assertTrue($peer->waitUntil(fn () => str_contains($peer->getOutput(), "\n")), $peer->getErrorOutput());
            $address = trim(explode("\n", $peer->getOutput())[0]);
            $this->assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/', $address);
            config(['contract_documents.clamav.enabled' => true, 'contract_documents.clamav.host' => '127.0.0.1',
                'contract_documents.clamav.port' => (int) substr($address, strrpos($address, ':') + 1), 'contract_documents.clamav.operation_seconds' => 1]);
            $contents = "%PDF-1.4\n".str_repeat('x', 150000)."\n%%EOF";
            $verdict = app(ClamAvDocumentScanner::class)->scan($contents);
            $this->assertSame($status, $verdict->status);
            $this->assertSame($reason, $verdict->reason);
            $peer->wait();
            if (! in_array($mode, ['stale', 'bad-version'], true)) {
                $lines = explode("\n", trim($peer->getOutput()));
                $received = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(hash('sha256', $contents), $received['hash']);
                $this->assertSame(strlen($contents), $received['bytes']);
                $this->assertSame(3, $received['chunks']);
            }
            $this->assertSame(0, $peer->getExitCode());
        } finally {
            $peer->stop();
        }
    }
}
