<?php

namespace Tests\Feature;

use App\Modules\Contracts\Infrastructure\Scanning\ClamAvDocumentScanner;
use Tests\TestCase;

class ClamAvIntegrationTest extends TestCase
{
    public function test_real_clamd_accepts_clean_pdf_and_blocks_an_embedded_eicar_test_string(): void
    {
        if (getenv('CONTRACT_TEST_CLAMAV') !== 'true') {
            $this->markTestSkipped('Opt-in test requires a real configured clamd and current signatures.');
        }
        config(['contract_documents.clamav.enabled' => true]);
        $scanner = app(ClamAvDocumentScanner::class);
        $clean = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF\n";
        $this->assertSame('clean', $scanner->scan($clean)->status);
        // Standard harmless EICAR probe, decoded in memory to avoid a plaintext fixture on disk.
        $probe = base64_decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=', true);
        $this->assertIsString($probe);
        $verdict = $scanner->scan("%PDF-1.4\n% ".$probe."\n%%EOF");
        $this->assertSame('rejected', $verdict->status);
        $this->assertSame('malware', $verdict->reason);
    }
}
