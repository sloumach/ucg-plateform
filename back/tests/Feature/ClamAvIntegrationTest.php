<?php

namespace Tests\Feature;

use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Infrastructure\Queue\ScanContractDocument;
use App\Modules\Contracts\Infrastructure\Scanning\ClamAvDocumentScanner;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Testing\Fakes\QueueFake;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Process\Process;
use Tests\Fixtures\AuthenticatesWithMfa;
use Tests\Fixtures\PdfAttachment;
use Tests\Fixtures\ProvidesContractDocuments;
use Tests\Fixtures\ProvidesPeopleAccess;
use Tests\TestCase;

class ClamAvIntegrationTest extends TestCase
{
    use AuthenticatesWithMfa, LazilyRefreshDatabase, ProvidesContractDocuments, ProvidesPeopleAccess;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_http_upload_and_serialized_worker_allow_only_clean_downloads_and_preserve_encrypted_quarantine(bool $dangerous): void
    {
        $real = getenv('CONTRACT_TEST_CLAMAV') === 'true';
        $peer = null;
        config(['contract_documents.clamav.enabled' => true]);
        if (! $real) {
            // Local transport coverage is explicit; the CI opt-in always uses real clamd.
            $peer = new Process([PHP_BINARY, base_path('tests/Fixtures/fake-clamd.php'), $dangerous ? 'malware' : 'clean'], base_path(), timeout: 15);
        }
        try {
            Storage::fake('local');
            Queue::fake([ScanContractDocument::class]);
            [$org, $user, $contract, $context] = $this->documentScenario();
            $document = $this->originalDocument($context, $contract);
            $this->actingAsWithMfa($user);
            $contents = PdfAttachment::create($real && $dangerous ? $this->eicar() : str_repeat('A', 68));
            $root = $this->documentPath($org, $contract, $document->id).'/versions';
            $versionId = $this->post($root, ['contract_version' => $contract->version, 'purpose' => 'draft',
                'file' => UploadedFile::fake()->createWithContent('quarantine.pdf', $contents)], ['Accept' => 'application/json'])
                ->assertCreated()->assertJsonPath('data.scan_status', 'queued')->assertJsonPath('data.download_available', false)->json('data.id');
            $this->assertIsString($versionId);
            $version = ContractDocumentVersion::findOrFail($versionId);
            $scan = ContractDocumentScan::query()->where('document_version_id', $version->id)->firstOrFail();
            $path = $root.'/'.$version->id;
            $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
            $storedPath = 'tenants/'.$org->id.'/artifacts/'.$version->artifact_id;
            $ciphertext = Storage::disk('local')->get($storedPath);
            $this->assertIsString($ciphertext);
            $this->assertStringNotContainsString($contents, $ciphertext);
            $this->assertSame($contents, decrypt($ciphertext, false));
            $queue = Queue::getFacadeRoot();
            $this->assertInstanceOf(QueueFake::class, $queue);
            Queue::assertPushed(ScanContractDocument::class, 1);
            $job = $queue->pushed(ScanContractDocument::class)->sole();
            $this->assertInstanceOf(ScanContractDocument::class, $job);
            $transport = serialize($job);
            Queue::fake()->except([ScanContractDocument::class]);
            if ($peer !== null) {
                $peer->start();
                $this->assertTrue($peer->waitUntil(fn () => str_contains($peer->getOutput(), "\n")), $peer->getErrorOutput());
                $address = trim(explode("\n", $peer->getOutput())[0]);
                $this->assertMatchesRegularExpression('/^127\.0\.0\.1:[0-9]+$/', $address);
                config(['contract_documents.clamav.host' => '127.0.0.1', 'contract_documents.clamav.port' => (int) substr($address, strrpos($address, ':') + 1)]);
            }
            Bus::dispatchSync(unserialize($transport));
            $this->assertSame($dangerous ? 'rejected' : 'clean', $scan->refresh()->status);
            $this->assertSame($dangerous ? 'malware' : null, $scan->reason);
            $before = $scan->getRawOriginal();
            Bus::dispatchSync(unserialize($transport));
            $this->assertSame($before, $scan->refresh()->getRawOriginal());
            $this->getJson($root)->assertOk()->assertJsonPath('data.0.download_available', ! $dangerous);
            if ($dangerous) {
                $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertConflict()->assertJsonPath('code', 'CONTRACT_DOCUMENT_NOT_READY');
                $this->getJson($path.'/download')->assertConflict();
            } else {
                $url = $this->postJson($path.'/download-link', ['expected_scan_id' => $scan->id])->assertOk()->json('data.url');
                $this->assertIsString($url);
                $response = $this->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Type', 'application/pdf');
                $this->assertSame($contents, $response->getContent());
                $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            }
            $this->assertSame($ciphertext, Storage::disk('local')->get($storedPath));
            $this->assertDatabaseCount('contract_document_scans', 1);
            $this->assertDatabaseCount('contract_document_versions', 1);
            $this->assertStringNotContainsString($contents, json_encode(DB::table('organization_audit_events')->get()->all(), JSON_THROW_ON_ERROR));
            if ($peer !== null) {
                $peer->wait();
                $this->assertSame(0, $peer->getExitCode(), $peer->getErrorOutput());
            }
        } finally {
            $peer?->stop();
        }
    }

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
