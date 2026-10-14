<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Validation\ContractDocumentInput as Input;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\Models\ContractDocument;
use App\Modules\Contracts\Domain\Models\ContractDocumentVersion;
use App\Modules\Contracts\Domain\Models\ContractRecord;
use App\Modules\Tenancy\Application\Contracts\TenantArtifacts;
use App\Modules\Tenancy\Application\Contracts\TenantAuditJournal;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditReason;
use App\Support\Audit\AuditTarget;
use App\Support\Audit\AuditWriteData;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class ContractDocumentService
{
    public function __construct(private ContractDocumentRepository $documents, private ContractRepository $contracts,
        private TenantAuthorization $authorization, private TenantBusinessGuard $guard, private TenantAuditJournal $audit,
        private TenantArtifacts $artifacts, private Encrypter $crypt, private Factory $validator, private ConnectionInterface $database,
        private ContractDocumentScanService $scans) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractDocument>
     */
    public function paginate(TenantContext $context, string $contractId, array $filters): LengthAwarePaginator
    {
        $this->read($context, $contractId);
        $values = ContractInput::validate($this->validator, $filters, ContractInput::pageRules(true));

        return $this->documents->paginate($context->organizationId, $contractId, $values);
    }

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractDocumentVersion>
     */
    public function versions(TenantContext $context, string $contractId, string $documentId, array $filters): LengthAwarePaginator
    {
        $this->read($context, $contractId);
        $this->documents->find($context->organizationId, $contractId, $documentId);
        $values = ContractInput::validate($this->validator, $filters, ContractInput::pageRules(true));

        return $this->documents->versions($context->organizationId, $contractId, $documentId, $values);
    }

    /** @param array<string, mixed> $input
     * @return array{document: ContractDocument, contract_version: string}
     */
    public function create(TenantContext $context, string $contractId, array $input, ?string $ipAddress = null): array
    {
        $this->authorizeWrite($context);
        $values = ContractInput::validate($this->validator, $input, Input::createRules(), Input::messages());
        try {
            return $this->database->transaction(function () use ($context, $contractId, $values, $ipAddress): array {
                $contract = $this->lock($context, $contractId, $values['contract_version']);
                if ($this->documents->count($context->organizationId, $contractId) >= Input::MAX_DOCUMENTS) {
                    $this->conflict('limit', 'CONTRACT_DOCUMENT_LIMIT');
                }
                $origin = $values['original_document_id'] ?? null;
                if ($values['kind'] === 'amendment') {
                    $original = $this->documents->find($context->organizationId, $contractId, $origin);
                    if ($original->kind !== 'original' || $original->signature === null || ! in_array($contract->status, Input::SIGNED_STATES, true)) {
                        $this->conflict('origin', 'CONTRACT_DOCUMENT_ORIGIN_REQUIRED');
                    }
                } elseif ($origin !== null || ($values['effective_on'] ?? null) !== null) {
                    throw ValidationException::withMessages(['original_document_id' => __('contract_documents.origin'), 'effective_on' => __('contract_documents.effective')]);
                }
                $document = $this->documents->create(['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
                    'reference' => $values['reference'], 'kind' => $values['kind'], 'title' => trim($values['title']),
                    'original_document_id' => $origin === null ? null : strtolower($origin), 'effective_on' => $values['effective_on'] ?? null,
                    'created_by' => $context->actorUserId, 'created_at' => CarbonImmutable::now()]);
                $next = $this->touch($context, $contract, $document->kind === 'original', $document, null, $ipAddress);

                return ['document' => $this->documents->find($context->organizationId, $contractId, $document->id), 'contract_version' => $next];
            });
        } catch (UniqueConstraintViolationException) {
            $this->conflict('duplicate', 'CONTRACT_DOCUMENT_REFERENCE_ALREADY_EXISTS');
        }
    }

    /** @param array<string, mixed> $input
     * @return array{version: ContractDocumentVersion, contract_version: string}
     */
    public function upload(TenantContext $context, string $contractId, string $documentId, array $input, ?string $ipAddress = null): array
    {
        $this->authorizeWrite($context);
        $values = ContractInput::validate($this->validator, $input, Input::uploadRules(), Input::messages());
        $this->authorizeWrite($context, $values['purpose'] === 'signed_copy');
        $contract = $this->read($context, $contractId);
        $this->assertCurrent($contract, $values['contract_version']);
        $document = $this->documents->find($context->organizationId, $contractId, $documentId);
        $this->assertUpload($contract, $document, $values['purpose']);
        /** @var UploadedFile $file */
        $file = $values['file'];
        $filename = $file->getClientOriginalName();
        if (mb_strlen($filename) > 160 || basename($filename) !== $filename || preg_match('/[\\x00-\\x1f\\x7f\\\\\\\\:%]/', $filename)) {
            throw ValidationException::withMessages(['file' => __('contract_documents.filename')]);
        }
        $contents = $file->getContent();
        if (! DocumentSecurity::pdf($contents)) {
            throw ValidationException::withMessages(['file' => __('contract_documents.file')]);
        }
        // Durable storage happens before metadata; a failed transaction removes only this new reservation.
        $artifact = $this->artifacts->store($context, 'contract-document.pdf.enc', $this->crypt->encryptString($contents));
        try {
            $result = $this->database->transaction(function () use ($context, $contractId, $documentId, $values, $artifact, $contents, $filename, $ipAddress): array {
                $contract = $this->lock($context, $contractId, $values['contract_version'], $values['purpose'] === 'signed_copy');
                $document = $this->documents->find($context->organizationId, $contractId, $documentId);
                $this->assertUpload($contract, $document, $values['purpose']);
                $version = $this->documents->addVersion(['organization_id' => $context->organizationId, 'contract_id' => $contract->id,
                    'document_id' => $document->id, 'artifact_id' => $artifact->id, 'number' => $document->versions_count + 1,
                    'purpose' => $values['purpose'], 'filename' => $filename, 'sha256' => hash('sha256', $contents), 'bytes' => strlen($contents),
                    'created_by' => $context->actorUserId, 'created_at' => CarbonImmutable::now()]);
                $next = $this->touch($context, $contract, $document->kind === 'original' && $values['purpose'] === 'draft', $document, $version->id, $ipAddress);

                return ['version' => $version, 'contract_version' => $next];
            });
        } catch (Throwable $exception) {
            try {
                $this->artifacts->delete($context, $artifact->id);
            } catch (Throwable $cleanupFailure) {
                // Keep the durable ledger for operator reconciliation if deletion is uncertain.
                report($cleanupFailure);
            }
            throw $exception;
        }
        try {
            $this->scans->request($context, $result['version']->contract_id, $documentId, $result['version']->id, ['expected_scan_id' => null]);
        } catch (Throwable $exception) {
            // The version is already committed. Preserve it in quarantine for an explicit scan retry.
            report($exception);
        }
        $result['version'] = $this->documents->version($context->organizationId, $result['version']->contract_id, $documentId, $result['version']->id);

        return $result;
    }

    /** @param array<string, mixed> $input
     * @return array{document: ContractDocument, contract_version: string}
     */
    public function sign(TenantContext $context, string $contractId, string $documentId, array $input, ?string $ipAddress = null): array
    {
        $this->authorizeWrite($context, true);
        $values = ContractInput::validate($this->validator, $input, Input::signatureRules(), Input::messages());

        return $this->database->transaction(function () use ($context, $contractId, $documentId, $values, $ipAddress): array {
            $contract = $this->lock($context, $contractId, $values['contract_version'], true);
            $document = $this->documents->find($context->organizationId, $contractId, $documentId);
            if ($document->signature !== null) {
                $this->conflict('frozen', 'CONTRACT_DOCUMENT_SIGNED');
            }
            if (! in_array($contract->status, Input::SIGNED_STATES, true)) {
                $this->conflict('signature_required', 'CONTRACT_DOCUMENT_SIGNATURE_REQUIRED');
            }
            $version = $this->documents->version($context->organizationId, $contractId, $documentId, $values['document_version_id']);
            if ($version->purpose !== 'signed_copy') {
                $this->conflict('signed_copy', 'CONTRACT_DOCUMENT_SIGNED_COPY_REQUIRED');
            }
            if ($document->kind === 'original' && $contract->signed_on?->format('Y-m-d') !== $values['signed_on']) {
                throw ValidationException::withMessages(['signed_on' => __('contract_documents.signature_date')]);
            }
            $this->documents->sign(['organization_id' => $context->organizationId, 'contract_id' => $contract->id, 'document_id' => $document->id,
                'document_version_id' => $version->id, 'version_number' => $version->number, 'sha256' => $version->sha256,
                'signed_on' => $values['signed_on'], 'recorded_by' => $context->actorUserId, 'created_at' => CarbonImmutable::now()]);
            $next = $this->touch($context, $contract, false, $document, $version->id, $ipAddress);

            return ['document' => $this->documents->find($context->organizationId, $contractId, $documentId), 'contract_version' => $next];
        });
    }

    private function read(TenantContext $context, string $id): ContractRecord
    {
        $this->authorization->authorize($context, 'administrative.read');

        return $this->contracts->find($context->organizationId, $id);
    }

    private function authorizeWrite(TenantContext $context, bool $validate = false): void
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->authorization->authorize($context, 'administrative.write');
        if ($validate) {
            $this->authorization->authorize($context, 'administrative.validate');
        }
    }

    private function lock(TenantContext $context, string $id, string $version, bool $validate = false): ContractRecord
    {
        $this->guard->lock($context);
        $this->authorizeWrite($context, $validate);
        $contract = $this->contracts->find($context->organizationId, $id, true);
        $this->assertCurrent($contract, $version);

        return $contract;
    }

    private function assertCurrent(ContractRecord $contract, string $version): void
    {
        if ($contract->version !== $version) {
            $this->conflict('stale', 'CONTRACT_VERSION_STALE');
        }
        if ($contract->status === 'archived') {
            $this->conflict('archived', 'CONTRACT_ARCHIVED');
        }
    }

    private function assertUpload(ContractRecord $contract, ContractDocument $document, string $purpose): void
    {
        if ($document->signature !== null) {
            $this->conflict('frozen', 'CONTRACT_DOCUMENT_SIGNED');
        }
        if ($document->versions_count >= Input::MAX_VERSIONS) {
            $this->conflict('limit', 'CONTRACT_DOCUMENT_VERSION_LIMIT');
        }
        if ($purpose === 'signed_copy' && ! in_array($contract->status, Input::SIGNED_STATES, true)) {
            $this->conflict('signature_required', 'CONTRACT_DOCUMENT_SIGNATURE_REQUIRED');
        }
        if ($purpose === 'draft' && $document->kind === 'original' && ! in_array($contract->status, ['draft', 'in_review'], true)) {
            $this->conflict('draft', 'CONTRACT_DOCUMENT_DRAFT_FROZEN');
        }
    }

    private function touch(TenantContext $context, ContractRecord $contract, bool $preparing, ContractDocument $document, ?string $versionId, ?string $ipAddress): string
    {
        $version = (string) Str::uuid();
        $saved = $this->contracts->save($contract, ['version' => $version,
            ...($preparing && in_array($contract->status, ['draft', 'in_review'], true) ? ['prepared_by' => $context->actorUserId] : [])]);
        $this->audit->record($context->organizationId, new AuditWriteData($context->actorUserId, AuditAction::DomainChanged,
            AuditTarget::Organization, $context->organizationId, AuditReason::Domain, $context->requestId, $ipAddress, [],
            ['contract_id' => $saved->id, 'contract_status' => $saved->status, 'contract_document_id' => $document->id,
                ...($versionId === null ? [] : ['contract_document_version_id' => $versionId]), 'version' => $version]));

        return $version;
    }

    private function conflict(string $message, string $code): never
    {
        throw new DomainConflictException(__('contract_documents.'.$message), $code);
    }
}
