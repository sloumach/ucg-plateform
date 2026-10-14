<?php

namespace App\Modules\Contracts\Application\Services;

use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanner;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\ScanVerdict;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Throwable;

final readonly class ContractDocumentScanProcessor
{
    public function __construct(private ContractDocumentScanRepository $scans, private ContractDocumentRepository $documents,
        private ContractRepository $contracts, private ContractDocumentScanner $scanner, private TenantArtifacts $artifacts,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private DocumentScanAudit $audit,
        private Encrypter $crypt, private ConnectionInterface $database) {}

    public function process(TenantContext $context, string $id): void
    {
        $scan = $this->database->transaction(function () use ($context, $id) {
            $this->guard->lock($context);
            $this->authorize($context);
            $scan = $this->scans->find($context->organizationId, $id, true);
            if ($scan->requested_by !== $context->actorUserId || $scan->status !== 'queued'
                || $this->scans->latest($context->organizationId, $scan->document_version_id)?->id !== $scan->id) {
                return null;
            }
            $this->scans->update($scan, ['status' => 'processing', 'started_at' => CarbonImmutable::now()]);
            $this->audit->record($context, $scan);

            return $scan;
        });
        if ($scan === null) {
            return;
        }
        $version = $this->documents->version($context->organizationId, $scan->contract_id, $scan->document_id, $scan->document_version_id);
        try {
            $contents = $this->crypt->decryptString($this->artifacts->read($context, $version->artifact_id));
            $verdict = strlen($contents) !== $version->bytes || ! hash_equals($version->sha256, hash('sha256', $contents))
                ? new ScanVerdict('rejected', 'integrity') : $this->scanner->scan($contents);
        } catch (Throwable $exception) {
            report($exception);
            $verdict = new ScanVerdict('failed', 'integrity');
        }
        $this->database->transaction(function () use ($context, $scan, $verdict): void {
            $this->guard->lock($context);
            $this->authorize($context);
            $this->contracts->find($context->organizationId, $scan->contract_id, true);
            $current = $this->scans->find($context->organizationId, $scan->id, true);
            if ($current->status !== 'processing' || $this->scans->latest($context->organizationId, $scan->document_version_id)?->id !== $scan->id) {
                return;
            }
            $this->scans->update($current, ['status' => $verdict->status, 'reason' => $verdict->reason,
                'engine' => $verdict->engine, 'finished_at' => CarbonImmutable::now()]);
            $this->audit->record($context, $current);
        });
    }

    /** Trusted queue terminal callback: it can only fail this actor's unfinished run, never grant access. */
    public function failed(string $org, string $id, int $actor): void
    {
        try {
            $this->database->transaction(function () use ($org, $id, $actor): void {
                $scan = $this->scans->find($org, $id, true);
                if ($scan->requested_by === $actor && in_array($scan->status, ['queued', 'processing'], true)) {
                    $this->scans->update($scan, ['status' => 'failed', 'reason' => 'worker_failed', 'finished_at' => CarbonImmutable::now()]);
                }
            });
        } catch (ModelNotFoundException) {
            // An absent maintenance target has no operational status to update.
        }
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->authorization->authorize($context, 'administrative.write');
    }
}
