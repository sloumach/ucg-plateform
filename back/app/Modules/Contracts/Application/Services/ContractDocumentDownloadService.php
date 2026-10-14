<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Data\DocumentDownload;
use App\Modules\Contracts\Application\Data\DocumentDownloadLink;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final readonly class ContractDocumentDownloadService
{
    public function __construct(private ContractDocumentRepository $documents, private ContractDocumentScanRepository $scans,
        private ContractRepository $contracts, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private TenantArtifacts $artifacts, private Encrypter $crypt, private UrlGenerator $urls,
        private DocumentScanAudit $audit, private ConnectionInterface $database) {}

    public function link(TenantContext $context, string $contractId, string $documentId, string $versionId, string $expectedScanId): DocumentDownloadLink
    {
        return $this->database->transaction(function () use ($context, $contractId, $documentId, $versionId, $expectedScanId): DocumentDownloadLink {
            [$version, $scan] = $this->ready($context, $contractId, $documentId, $versionId, $expectedScanId);
            $expires = CarbonImmutable::now()->addSeconds(DocumentSecurity::LINK_SECONDS);

            return new DocumentDownloadLink($this->urls->temporarySignedRoute('api.v1.contract-document-security.download', $expires,
                ['tenant' => $context->organizationId, 'contract' => $version->contract_id, 'document' => $version->document_id,
                    'version' => $version->id, 'actor' => $context->actorUserId, 'scan' => $scan->id], false), $expires->toIso8601String(), $version->filename);
        });
    }

    public function download(TenantContext $context, string $contractId, string $documentId, string $versionId, string $scanId, int $expires): DocumentDownload
    {
        $version = $this->database->transaction(fn () => $this->ready($context, $contractId, $documentId, $versionId, $scanId)[0]);
        try {
            $contents = $this->crypt->decryptString($this->artifacts->read($context, $version->artifact_id));
        } catch (Throwable $exception) {
            report($exception);
            $this->invalidate($context, $version, $scanId);
            throw new DomainConflictException(__('document_security.integrity'), 'CONTRACT_DOCUMENT_INTEGRITY_FAILURE');
        }
        if (strlen($contents) !== $version->bytes || ! hash_equals($version->sha256, hash('sha256', $contents)) || ! DocumentSecurity::pdf($contents)) {
            $this->invalidate($context, $version, $scanId);
            throw new DomainConflictException(__('document_security.integrity'), 'CONTRACT_DOCUMENT_INTEGRITY_FAILURE');
        }

        // Recheck current access and latest verdict after storage I/O, before returning any clear bytes.
        return $this->database->transaction(function () use ($context, $contractId, $documentId, $versionId, $scanId, $expires, $contents): DocumentDownload {
            if ($expires <= CarbonImmutable::now()->getTimestamp()) {
                throw new DomainConflictException(__('document_security.invalid_link'), 'CONTRACT_DOCUMENT_LINK_INVALID');
            }
            [$current] = $this->ready($context, $contractId, $documentId, $versionId, $scanId);

            return new DocumentDownload($contents, $current->filename);
        });
    }

    /** @return array{ContractDocumentVersion, ContractDocumentScan} */
    private function ready(TenantContext $context, string $contractId, string $documentId, string $versionId, string $expected): array
    {
        $this->guard->lock($context);
        $this->authorization->authorize($context, 'administrative.read');
        $this->contracts->find($context->organizationId, $contractId, true);
        $version = $this->documents->version($context->organizationId, $contractId, $documentId, $versionId);
        $scan = $this->scans->latest($context->organizationId, $version->id, true);
        if (! DocumentSecurity::downloadable($scan, CarbonImmutable::now()) || $scan === null
            || $scan->id !== strtolower($expected) || ! hash_equals($version->sha256, $scan->sha256)) {
            throw new DomainConflictException(__('document_security.not_ready'), 'CONTRACT_DOCUMENT_NOT_READY');
        }

        return [$version, $scan];
    }

    private function invalidate(TenantContext $context, ContractDocumentVersion $version, string $scanId): void
    {
        $this->database->transaction(function () use ($context, $version, $scanId): void {
            $this->guard->lock($context);
            $this->authorization->authorize($context, 'administrative.read');
            $scan = $this->scans->find($context->organizationId, $scanId, true);
            if ($scan->status === 'clean' && $scan->invalidated_at === null && $scan->document_version_id === $version->id) {
                $this->scans->update($scan, ['invalidated_at' => CarbonImmutable::now()]);
                $this->audit->record($context, $scan);
            }
        });
    }
}
