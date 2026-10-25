<?php

namespace Tests\Feature;

use App\Modules\Contracts\Infrastructure\Scanning\ClamAvDocumentScanner;
use Tests\Fixtures\PdfAttachment;
use Tests\TestCase;

class ClamAvIntegrationTest extends TestCase
{
    public function test_real_clamd_accepts_clean_pdf_and_blocks_eicar_in_an_embedded_file(): void
    {
        $this->requireRealScanner();
        config(['contract_documents.clamav.enabled' => true]);
        $scanner = app(ClamAvDocumentScanner::class);
        $clean = PdfAttachment::create(str_repeat('A', 68));
        $this->assertSame('clean', $scanner->scan($clean)->status);
        $verdict = $scanner->scan(PdfAttachment::create($this->eicar()));
        $this->assertSame('rejected', $verdict->status);
        $this->assertSame('malware', $verdict->reason);
    }

    public function test_real_clamd_recognizes_the_standard_eicar_probe_over_instream(): void
    {
        $this->requireRealScanner();
        $probe = $this->eicar();
        $host = config('contract_documents.clamav.host');
        $port = config('contract_documents.clamav.port');
        $socket = stream_socket_client('tcp://'.$host.':'.$port, $errorNumber, $errorMessage, 3);
        $this->assertIsResource($socket);
        try {
            stream_set_timeout($socket, 20);
            $frame = "zINSTREAM\0".pack('N', strlen($probe)).$probe.pack('N', 0);
            $this->assertSame(strlen($frame), fwrite($socket, $frame));
            $reply = stream_get_contents($socket, 513);
            $this->assertIsString($reply);
            $this->assertMatchesRegularExpression('/\Astream: [^\r\n\x00]{1,180} FOUND\x00\z/D', $reply);
            $this->assertTrue(feof($socket), 'The non-session scanner must close its reply.');
        } finally {
            fclose($socket);
        }
    }

    private function requireRealScanner(): void
    {
        if (getenv('CONTRACT_TEST_CLAMAV') !== 'true') {
            $this->markTestSkipped('Opt-in test requires a real configured clamd and current signatures.');
        }
    }

    private function eicar(): string
    {
        // Decode only in memory; never persist the probe as a plaintext fixture.
        $probe = base64_decode('WDVPIVAlQEFQWzRcUFpYNTQoUF4pN0NDKTd9JEVJQ0FSLVNUQU5EQVJELUFOVElWSVJVUy1URVNULUZJTEUhJEgrSCo=', true);
        $this->assertIsString($probe);
        $this->assertSame(68, strlen($probe));
        $this->assertSame('275a021bbfb6489e54d471899f7db9d1663fc695ec2fe2a2c4538aabf651fd0f', hash('sha256', $probe));

        return $probe;
    }
}
