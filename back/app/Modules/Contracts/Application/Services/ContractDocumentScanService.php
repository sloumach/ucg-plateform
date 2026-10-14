<?php

namespace App\Modules\Contracts\Application\Services;

use App\Exceptions\DomainConflictException;
use App\Modules\Contracts\Application\Contracts\ContractDocumentScanQueue;
use App\Modules\Contracts\Application\Validation\ContractInput;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentRepository;
use App\Modules\Contracts\Domain\Contracts\ContractDocumentScanRepository;
use App\Modules\Contracts\Domain\Contracts\ContractRepository;
use App\Modules\Contracts\Domain\DocumentSecurity;
use App\Modules\Contracts\Domain\Models\ContractDocumentScan;
use App\Modules\Tenancy\Application\Contracts\TenantAuthorization;
use App\Modules\Tenancy\Application\Contracts\TenantBusinessGuard;
use App\Modules\Tenancy\Application\Contracts\TenantContext;
use App\Support\Queue\JobContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Database\ConnectionInterface;
use Throwable;

final readonly class ContractDocumentScanService
{
    public function __construct(private ContractDocumentScanRepository $scans, private ContractDocumentRepository $documents,
        private ContractRepository $contracts, private TenantAuthorization $authorization, private TenantBusinessGuard $guard,
        private ContractDocumentScanQueue $queue, private DocumentScanAudit $audit, private ConnectionInterface $database, private Factory $validator) {}

    /** @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, ContractDocumentScan>
     */
    public function paginate(TenantContext $context, string $contractId, string $documentId, string $versionId, array $filters): LengthAwarePaginator
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->contracts->find($context->organizationId, $contractId);
        $version = $this->documents->version($context->organizationId, $contractId, $documentId, $versionId);
        $values = ContractInput::validate($this->validator, $filters, ContractInput::pageRules(true));

        return $this->scans->paginate($context->organizationId, $version->id, $values);
    }

    /** @param array<string, mixed> $input */
    public function request(TenantContext $context, string $contractId, string $documentId, string $versionId, array $input): ContractDocumentScan
    {
        $this->authorize($context);
        $values = ContractInput::validate($this->validator, $input, ['expected_scan_id' => ['present', 'nullable', 'uuid']]);
        [$scan, $created] = $this->database->transaction(function () use ($context, $contractId, $documentId, $versionId, $values): array {
            $this->guard->lock($context);
            $this->authorize($context);
            $this->contracts->find($context->organizationId, $contractId, true);
            $version = $this->documents->version($context->organizationId, $contractId, $documentId, $versionId);
            $latest = $this->scans->latest($context->organizationId, $version->id, true);
            $now = CarbonImmutable::now();
            if ($latest !== null && in_array($latest->status, ['queued', 'processing'], true)) {
                if ($latest->requested_at->addSeconds(DocumentSecurity::LEASE_SECONDS)->gt($now)) {
                    return [$latest, false];
                }
                $this->scans->update($latest, ['status' => 'failed', 'reason' => 'timed_out', 'finished_at' => $now]);
                $this->audit->record($context, $latest);
            }
            $expected = $values['expected_scan_id'] === null ? null : strtolower($values['expected_scan_id']);
            if ($expected !== $latest?->id) {
                throw new DomainConflictException(__('document_security.stale'), 'CONTRACT_DOCUMENT_SCAN_STALE');
            }
            $attempt = ($latest->attempt ?? 0) + 1;
            if ($attempt > DocumentSecurity::MAX_ATTEMPTS) {
                throw new DomainConflictException(__('document_security.limit'), 'CONTRACT_DOCUMENT_SCAN_LIMIT');
            }
            $scan = $this->scans->create(['organization_id' => $context->organizationId, 'contract_id' => $version->contract_id,
                'document_id' => $version->document_id, 'document_version_id' => $version->id, 'attempt' => $attempt,
                'sha256' => $version->sha256, 'policy' => DocumentSecurity::POLICY, 'status' => 'queued', 'reason' => null,
                'engine' => null, 'requested_by' => $context->actorUserId, 'requested_at' => $now, 'started_at' => null,
                'finished_at' => null, 'invalidated_at' => null]);
            $this->audit->record($context, $scan);

            return [$scan, true];
        });
        if ($created) {
            try {
                $this->queue->dispatch(new JobContext($context->requestId, $context->organizationId, $context->actorUserId), $scan->id);
            } catch (Throwable $exception) {
                $this->database->transaction(function () use ($context, $scan): void {
                    $this->guard->lock($context);
                    $current = $this->scans->find($context->organizationId, $scan->id, true);
                    if (in_array($current->status, ['queued', 'processing'], true)) {
                        $this->scans->update($current, ['status' => 'failed', 'reason' => 'dispatch_failed', 'finished_at' => CarbonImmutable::now()]);
                        $this->audit->record($context, $current);
                    }
                });
                report($exception);
            }
        }

        return $this->scans->find($context->organizationId, $scan->id);
    }

    private function authorize(TenantContext $context): void
    {
        $this->authorization->authorize($context, 'administrative.read');
        $this->authorization->authorize($context, 'administrative.write');
    }
}
